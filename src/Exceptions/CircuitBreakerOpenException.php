<?php

namespace Kkxdev\AppleIap\Exceptions;

class CircuitBreakerOpenException extends AppleIapException
{
    public function __construct(string $service, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Apple IAP circuit breaker is open for service '{$service}'. "
            . "Too many recent failures — requests are being blocked until the service recovers.",
            0,
            $previous
        );
    }
}
