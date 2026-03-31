<?php

namespace Kkxdev\AppleIap\Support;

use Kkxdev\AppleIap\Exceptions\InvalidEnvironmentException;

class EnvironmentResolver
{
    private string $environment;

    public function __construct(array $config)
    {
        $env = strtolower($config['environment'] ?? 'production');

        if (!in_array($env, ['production', 'sandbox'], true)) {
            throw new InvalidEnvironmentException(
                "Invalid environment '{$env}'. Must be 'production' or 'sandbox'."
            );
        }

        $this->environment = $env;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function isSandbox(): bool
    {
        return $this->environment === 'sandbox';
    }

    public function getReceiptValidationUrl(array $urlConfig): string
    {
        return $urlConfig['receipt_validation'][$this->environment]
            ?? throw new InvalidEnvironmentException("Missing receipt validation URL for environment: {$this->environment}");
    }

    public function getServerApiBaseUrl(array $urlConfig): string
    {
        return $urlConfig['server_api'][$this->environment]
            ?? throw new InvalidEnvironmentException("Missing server API URL for environment: {$this->environment}");
    }

    /**
     * Allow runtime override (e.g. when a receipt is detected as sandbox).
     */
    public function withEnvironment(string $env): self
    {
        $clone = clone $this;
        $clone->environment = strtolower($env);
        return $clone;
    }
}
