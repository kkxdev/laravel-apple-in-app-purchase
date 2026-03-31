<?php

namespace Kkxdev\AppleIap\DTO\Enums;

use Kkxdev\AppleIap\Exceptions\InvalidEnvironmentException;

/**
 * Apple environment identifiers.
 * PHP 8.0-compatible constant class (upgrade to backed enum when dropping 8.0 support).
 */
final class Environment
{
    public const PRODUCTION = 'Production';
    public const SANDBOX    = 'Sandbox';

    public static function from(string $value): string
    {
        return match (strtolower($value)) {
            'production' => self::PRODUCTION,
            'sandbox'    => self::SANDBOX,
            default      => throw new InvalidEnvironmentException("Unknown environment: {$value}"),
        };
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, [self::PRODUCTION, self::SANDBOX], true);
    }

    private function __construct()
    {
    }
}
