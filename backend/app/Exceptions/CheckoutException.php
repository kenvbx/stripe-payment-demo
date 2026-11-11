<?php

namespace App\Exceptions;

use RuntimeException;

class CheckoutException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $httpStatus, public readonly bool $retryable = false, public readonly bool $restartAllowed = false)
    {
        parent::__construct($message);
    }
}
