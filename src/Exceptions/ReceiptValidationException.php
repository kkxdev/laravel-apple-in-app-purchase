<?php

namespace Kkxdev\AppleIap\Exceptions;

class ReceiptValidationException extends AppleIapException
{
    public function __construct(
        string $message,
        private int $statusCode = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public static function fromStatus(int $status): self
    {
        return new self(self::describeStatus($status), $status);
    }

    private static function describeStatus(int $status): string
    {
        return match ($status) {
            21000 => 'The request to the App Store was not made using the HTTP POST request method.',
            21001 => 'This status code is no longer sent by the App Store.',
            21002 => 'The data in the receipt-data property was malformed or the service experienced a temporary issue.',
            21003 => 'The receipt could not be authenticated.',
            21004 => 'The shared secret you provided does not match the shared secret on file for your account.',
            21005 => 'The receipt server was temporarily unable to provide the receipt.',
            21006 => 'This receipt is valid but the subscription has expired.',
            21007 => 'This receipt is from the test environment but was sent to the production environment.',
            21008 => 'This receipt is from the production environment but was sent to the test environment.',
            21009 => 'Internal data access error. Try again later.',
            21010 => 'The user account cannot be found or has been deleted.',
            default => "Unknown receipt validation status: {$status}.",
        };
    }
}
