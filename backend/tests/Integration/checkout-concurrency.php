<?php

// Run only against a disposable, migrated PostgreSQL database. No real Stripe calls.
use App\Exceptions\CheckoutException;
use App\Models\Payment;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\StripeCheckoutGateway;
use Illuminate\Contracts\Console\Kernel;
use Stripe\Checkout\Session;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'stripe_demo_ci') {
    throw new RuntimeException('Concurrency fixture requires the disposable stripe_demo_ci PostgreSQL database.');
}
config(['services.stripe.secret' => 'sk_test_concurrency_fixture', 'services.stripe.success_url' => 'https://example.test/success', 'services.stripe.cancel_url' => 'https://example.test/cancel']);

if (($argv[1] ?? '') === 'worker') {
    [$script, $mode, $key, $productId, $counter, $release] = $argv;
    $app->instance(StripeCheckoutGateway::class, new class($counter, $release) extends StripeCheckoutGateway {
        public function __construct(private string $counter, private string $release) {}
        public function create(Payment $payment): Session
        {
            if (\Illuminate\Support\Facades\DB::transactionLevel() !== 0) throw new RuntimeException('External call inside a transaction');
            file_put_contents($this->counter, $payment->id."\n", FILE_APPEND | LOCK_EX);
            $deadline = microtime(true) + 20;
            while (!is_file($this->release) && microtime(true) < $deadline) { usleep(20000); clearstatcache(true, $this->release); }
            if (!is_file($this->release)) throw new RuntimeException('Timed out waiting for concurrent request');
            return Session::constructFrom(['object' => 'checkout.session', 'id' => 'cs_'.$payment->id, 'url' => 'https://checkout.stripe.com/fixture', 'expires_at' => time() + 3600]);
        }
    });
    try {
        $result = $app->make(CheckoutService::class)->create(['product_id' => (int) $productId, 'customer_name' => 'Concurrency fixture', 'customer_email' => 'ci@example.test', 'idempotency_key' => $key]);
        echo json_encode(['status' => $result['replay'] ? 200 : 201, 'id' => $result['payment']->id]);
    } catch (CheckoutException $exception) {
        echo json_encode(['status' => $exception->httpStatus, 'code' => $exception->errorCode]);
    }
    exit;
}

$key = 'concurrency-'.bin2hex(random_bytes(12));
$counter = tempnam(sys_get_temp_dir(), 'checkout-counter-');
$release = $counter.'.release';
$product = Product::where('is_active', true)->firstOrFail();
$start = function () use ($key, $product, $counter, $release): array {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $key, (string) $product->id, $counter, $release], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start checkout worker');
    return [$process, $pipes];
};
$finish = function (array $worker): array {
    [$process, $pipes] = $worker;
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Worker failed: '.$stderr);
    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
};
try {
    $first = $start();
    $deadline = microtime(true) + 10;
    while (filesize($counter) === 0 && microtime(true) < $deadline) { usleep(20000); clearstatcache(true, $counter); }
    if (filesize($counter) === 0) throw new RuntimeException('First worker never acquired a checkout claim');
    $second = $start();
    $secondResult = $finish($second);
    touch($release);
    $results = [$finish($first), $secondResult];
    if ($results[0]['status'] !== 201 || $results[1]['status'] !== 409 || ($results[1]['code'] ?? '') !== 'CHECKOUT_IN_PROGRESS') {
        throw new RuntimeException('Unexpected concurrent checkout results: '.json_encode($results));
    }
    $replay = $finish($start());
    if ($replay['status'] !== 200 || $replay['id'] !== $results[0]['id']) throw new RuntimeException('Replay did not reuse payment');
    if (Payment::where('idempotency_key', $key)->count() !== 1 || count(file($counter, FILE_IGNORE_NEW_LINES)) !== 1) throw new RuntimeException('Duplicate payment or Stripe call');
    echo "PostgreSQL concurrency passed: overlapping requests, one payment, one gateway call, stable replay.\n";
} finally {
    Payment::where('idempotency_key', $key)->delete();
    touch($release);
    unlink($counter);
    unlink($release);
}
