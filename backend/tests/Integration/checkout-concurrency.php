<?php

// Independent processes exercise committed writes and PostgreSQL locks without PHPUnit transactions.
use App\Exceptions\CheckoutException;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\StripeCheckoutGateway;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = (string) DB::connection()->getDatabaseName();
if (config('database.default') !== 'pgsql'
    || ($database !== 'stripe_demo_ci' && !preg_match('/^stripe_demo_test(?:_[a-z0-9]+)?$/', $database))) {
    throw new RuntimeException('Concurrency tests require a disposable stripe_demo_ci or stripe_demo_test PostgreSQL database.');
}
DB::statement("SET statement_timeout = '15s'");
DB::statement("SET lock_timeout = '10s'");
config(['services.stripe.secret' => 'sk_test_concurrency_fixture', 'services.stripe.success_url' => 'https://example.test/success', 'services.stripe.cancel_url' => 'https://example.test/cancel']);

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

if (($argv[1] ?? '') === 'worker') {
    [$script, $mode, $key, $productId, $directory, $label, $scenario] = $argv;
    if ($scenario === 'insert-race') {
        // Both transactions have observed a missing key before either INSERT may proceed.
        Payment::creating(function () use ($directory, $label): void {
            touch($directory.'/'.$label.'.ready');
            waitFor(fn () => is_file($directory.'/insert.release'), 'Insert barrier timed out');
        });
    }
    $app->instance(StripeCheckoutGateway::class, new class($directory) extends StripeCheckoutGateway {
        public function __construct(private string $directory) {}
        public function create(Payment $payment): Session
        {
            if (DB::transactionLevel() !== 0) throw new RuntimeException('External call inside a transaction');
            file_put_contents($this->directory.'/calls', json_encode([
                'id' => $payment->id, 'key' => $payment->idempotency_key, 'parameters' => $payment->checkout_parameters,
            ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            waitFor(fn () => is_file($this->directory.'/gateway.release'), 'Gateway barrier timed out');
            return Session::constructFrom(['object' => 'checkout.session', 'id' => 'cs_'.$payment->id, 'url' => 'https://checkout.stripe.com/fixture', 'expires_at' => time() + 3600]);
        }
    });
    try {
        $result = $app->make(CheckoutService::class)->create([
            'product_id' => (int) $productId, 'customer_name' => 'Concurrency fixture',
            'customer_email' => $scenario === 'conflict' ? 'other@example.test' : 'ci@example.test', 'idempotency_key' => $key,
        ]);
        $output = ['status' => $result['replay'] ? 200 : 201, 'id' => $result['payment']->id];
    } catch (CheckoutException $exception) {
        $output = ['status' => $exception->httpStatus, 'code' => $exception->errorCode];
    }
    file_put_contents($directory.'/'.$label.'.done', json_encode($output, JSON_THROW_ON_ERROR));
    echo json_encode($output, JSON_THROW_ON_ERROR);
    exit;
}

$product = Product::where('is_active', true)->firstOrFail();
$runId = bin2hex(random_bytes(12));
$root = sys_get_temp_dir().'/checkout-'.$runId;
mkdir($root, 0700);
$workers = [];
$keys = [];
$start = function (string $key, string $directory, string $label, string $scenario = 'overlap') use ($product, &$workers): int {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $key, (string) $product->id, $directory, $label, $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start checkout worker');
    $workers[] = ['process' => $process, 'pipes' => $pipes, 'directory' => $directory, 'label' => $label];
    return array_key_last($workers);
};
$finish = function (int $index) use (&$workers): array {
    $worker = $workers[$index];
    waitFor(fn () => is_file($worker['directory'].'/'.$worker['label'].'.done') || !proc_get_status($worker['process'])['running'], 'Checkout worker timed out');
    $stdout = stream_get_contents($worker['pipes'][1]); $stderr = stream_get_contents($worker['pipes'][2]);
    foreach ($worker['pipes'] as $pipe) fclose($pipe);
    $exit = proc_close($worker['process']);
    unset($workers[$index]);
    if ($exit !== 0) throw new RuntimeException('Worker failed: '.$stderr);
    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
};
$verify = function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
try {
    foreach (['overlap', 'insert-race'] as $scenario) {
        $directory = $root.'/'.$scenario;
        mkdir($directory, 0700);
        $key = 'concurrency-'.$runId.'-'.$scenario;
        $keys[] = $key;
        $first = $start($key, $directory, 'a', $scenario);
        if ($scenario === 'overlap') {
            waitFor(fn () => is_file($directory.'/calls'), 'First worker never reached Stripe gateway');
            $conflict = $finish($start($key, $directory, 'conflict', 'conflict'));
            $verify($conflict === ['status' => 409, 'code' => 'IDEMPOTENCY_CONFLICT'], 'Conflicting input was not rejected');
        }
        $second = $start($key, $directory, 'b', $scenario);
        if ($scenario === 'insert-race') {
            waitFor(fn () => is_file($directory.'/a.ready') && is_file($directory.'/b.ready'), 'Workers did not both reach the insert barrier');
            touch($directory.'/insert.release');
        }
        // One worker must return in-progress while the gateway owner remains blocked.
        waitFor(fn () => is_file($directory.'/a.done') || is_file($directory.'/b.done'), 'Duplicate request blocked behind the external call');
        touch($directory.'/gateway.release');
        $results = [$finish($first), $finish($second)];
        $statuses = array_column($results, 'status'); sort($statuses);
        // The request which inserted the row need not be the one which acquires the gateway claim.
        $verify(in_array($statuses, [[201, 409], [200, 409]], true), 'Unexpected concurrent results: '.json_encode($results));
        $blocked = array_values(array_filter($results, fn ($result) => $result['status'] === 409))[0];
        $verify($blocked['code'] === 'CHECKOUT_IN_PROGRESS', 'Duplicate did not observe active claim');
        $calls = array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($directory.'/calls', FILE_IGNORE_NEW_LINES));
        $verify(count($calls) === 1, 'More than one Stripe call');
        $payment = Payment::where('idempotency_key', $key)->sole();
        $verify($payment->id === $calls[0]['id'] && $payment->checkout_state === 'complete', 'Wrong payment finalized');
        $verify($payment->checkout_parameters === $calls[0]['parameters'], 'Snapshot changed during concurrent insert');
        $verify($payment->checkout_claim_token === null && $payment->checkout_claim_expires_at === null, 'Claim was not released');
        $replay = $finish($start($key, $directory, 'replay'));
        $verify($replay === ['status' => 200, 'id' => $payment->id], 'Replay did not reuse payment');
        $verify(count(file($directory.'/calls')) === 1, 'Replay called Stripe again');
        echo "PostgreSQL {$scenario} passed: one payment, one gateway call, stable snapshot and replay.\n";
    }
} finally {
    foreach ($workers as $worker) {
        proc_terminate($worker['process']);
        foreach ($worker['pipes'] as $pipe) fclose($pipe);
        proc_close($worker['process']);
    }
    Payment::whereIn('idempotency_key', $keys)->delete();
    foreach (glob($root.'/*') as $directory) {
        foreach (glob($directory.'/*') as $file) unlink($file);
        rmdir($directory);
    }
    rmdir($root);
}
