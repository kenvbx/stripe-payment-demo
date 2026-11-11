<?php

namespace App\Services;

use App\Models\Payment;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

class StripeCheckoutGateway
{
    public function __construct(private ?ClientInterface $http = null) {}

    public function create(Payment $payment): Session
    {
        return $this->request(fn (StripeClient $client) => $client->checkout->sessions->create($payment->checkout_parameters, [
            'idempotency_key' => 'checkout-'.$payment->idempotency_key,
        ]));
    }

    public function retrieve(string $sessionId): Session
    {
        return $this->request(fn (StripeClient $client) => $client->checkout->sessions->retrieve($sessionId));
    }

    private function request(callable $operation): Session
    {
        $http = $this->http;
        if (!$http) {
            $http = new CurlClient();
            $http->setConnectTimeout(5);
            $http->setTimeout(20);
        }
        $previous = ApiRequestor::httpClient();
        ApiRequestor::setHttpClient($http);
        try {
            $client = new StripeClient(['api_key' => config('services.stripe.secret'), 'max_network_retries' => 1]);
            return $operation($client);
        } finally {
            ApiRequestor::setHttpClient($previous);
        }
    }
}
