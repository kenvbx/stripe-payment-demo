<?php

// Exercise committed API/webhook writes in separate PostgreSQL processes.
use App\Http\Controllers\Api\PaymentController;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\RefundStateMachine;
use App\Services\StripeRefundService;
use App\Services\StripeWebhookProcessor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Stripe\Event;
use Stripe\Refund;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = (string) DB::connection()->getDatabaseName();
if (config('database.default') !== 'pgsql'
    || ($database !== 'stripe_demo_ci' && !preg_match('/^stripe_demo_test(?:_[a-z0-9]+)?$/', $database))) {
    throw new RuntimeException('Refund concurrency tests require a disposable PostgreSQL test database.');
}
DB::statement("SET statement_timeout = '15s'");
DB::statement("SET lock_timeout = '10s'");
config(['services.stripe.secret' => 'sk_test_refund_fixture']);

function waitFor(callable $condition, string $message): void
{
    $deadline = microtime(true) + 20;
    do {
        clearstatcache();
        if ($condition()) return;
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException($message);
}

function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function gateway(string $directory): StripeRefundService
{
    return new class($directory) extends StripeRefundService {
        public function __construct(private string $directory) {}
        public function createRefund(Payment $payment): Refund
        {
            verify(DB::transactionLevel() === 0, 'Stripe call must happen outside a transaction');
            file_put_contents($this->directory.'/calls', "refund\n", FILE_APPEND | LOCK_EX);
            touch($this->directory.'/api.ready');
            waitFor(fn () => is_file($this->directory.'/api.release'), 'API response barrier timed out');
            return Refund::constructFrom(['id' => 're_'.$payment->idempotency_key, 'status' => 'pending']);
        }
    };
}

if (($argv[1] ?? '') === 'worker') {
    [$script, $worker, $mode, $paymentId, $directory] = $argv;
    $payment = Payment::findOrFail($paymentId);
    if ($mode === 'api') {
        $response = $app->make(PaymentController::class)->refund($payment, gateway($directory), new RefundStateMachine);
        $result = ['status' => $response->getStatusCode(), 'body' => $response->getData(true)];
    } else {
        $payload = json_encode([
            'id' => 'evt_'.$payment->idempotency_key, 'type' => 'refund.updated',
            'data' => ['object' => ['id' => 're_'.$payment->idempotency_key, 'payment_intent' => $payment->stripe_payment_intent_id, 'status' => 'succeeded', 'amount' => $payment->amount]],
        ], JSON_THROW_ON_ERROR);
        // Signature verification is exercised by the HTTP feature suite; this worker tests database contention.
        $event = $app->make(StripeWebhookProcessor::class)->recordAndProcess(Event::constructFrom(json_decode($payload, true)), $payload);
        $result = ['status' => $event->status];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
    touch($directory.'/'.$mode.'.done');
    exit;
}

$runId = 'refund-'.bin2hex(random_bytes(12));
$directory = sys_get_temp_dir().'/'.$runId;
mkdir($directory, 0700);
$workers = [];
$payment = Payment::create([
    'customer_name' => 'Concurrency fixture', 'customer_email' => 'ci@example.test',
    'product_name' => 'Refund fixture', 'amount' => 8900, 'currency' => 'usd',
    'status' => 'succeeded', 'webhook_status' => 'processed', 'paid_at' => now()->subDay(),
    'stripe_payment_intent_id' => 'pi_'.$runId, 'idempotency_key' => $runId,
]);
$start = function (string $mode) use ($payment, $directory, &$workers): int {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $mode, (string) $payment->id, $directory], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start refund worker');
    $workers[] = ['process' => $process, 'pipes' => $pipes, 'mode' => $mode];
    return array_key_last($workers);
};
$finish = function (int $index) use (&$workers, $directory): array {
    $worker = $workers[$index];
    waitFor(fn () => is_file($directory.'/'.$worker['mode'].'.done') || !proc_get_status($worker['process'])['running'], 'Refund worker timed out');
    $stdout = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    foreach ($worker['pipes'] as $pipe) fclose($pipe);
    $exit = proc_close($worker['process']);
    unset($workers[$index]);
    if ($exit !== 0) throw new RuntimeException('Refund worker failed: '.$stderr);
    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
};
try {
    $paidAt = $payment->fresh()->paid_at;
    $api = $start('api');
    waitFor(fn () => is_file($directory.'/api.ready'), 'API did not reach Stripe gateway');
    verify($finish($start('webhook'))['status'] === 'processed', 'Webhook did not commit while API was waiting');
    $confirmedAt = $payment->fresh()->refunded_at;
    verify($confirmedAt !== null, 'Webhook did not confirm refund');
    touch($directory.'/api.release');
    $response = $finish($api);
    verify($response['status'] === 201 && $response['body']['refund_status'] === 'succeeded', 'Delayed pending API response regressed refund status');
    verify($response['body']['data']['status'] === 'refunded', 'API did not report persisted payment state');
    $fresh = $payment->fresh();
    verify($fresh->refund_status === 'succeeded' && $fresh->status === 'refunded', 'Database refund state regressed');
    verify($paidAt->equalTo($fresh->paid_at) && $confirmedAt->equalTo($fresh->refunded_at), 'Original timestamps changed');
    $replay = $app->make(PaymentController::class)->refund($fresh, gateway($directory), new RefundStateMachine);
    verify($replay->getStatusCode() === 200 && $replay->getData(true)['idempotent_replay'], 'Completed refund replay failed');
    verify(count(file($directory.'/calls')) === 1, 'Replay called Stripe again');
    echo "PostgreSQL refund race passed: webhook success survives delayed API response; replay makes no extra Stripe call.\n";
} finally {
    foreach ($workers as $worker) {
        proc_terminate($worker['process']);
        foreach ($worker['pipes'] as $pipe) fclose($pipe);
        proc_close($worker['process']);
    }
    WebhookEvent::where('event_id', 'evt_'.$runId)->delete();
    $payment->delete();
    foreach (glob($directory.'/*') as $file) unlink($file);
    rmdir($directory);
}
