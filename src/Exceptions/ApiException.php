<?php

namespace Kkxdev\AppleIap\Exceptions;

class ApiException extends AppleIapException
{
    public function __construct(
        string $message,
        private int $httpStatus = 0,
        private ?string $errorCode = null,
        private ?string $errorMessage = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
