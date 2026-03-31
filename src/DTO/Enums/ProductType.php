<?php

namespace Kkxdev\AppleIap\DTO\Enums;

use Kkxdev\AppleIap\Exceptions\AppleIapException;

/**
 * Apple In-App Purchase product types.
 * PHP 8.0-compatible constant class.
 */
final class ProductType
{
    public const AUTO_RENEWABLE_SUBSCRIPTION = 'Auto-Renewable Subscription';
    public const NON_CONSUMABLE              = 'Non-Consumable';
    public const CONSUMABLE                  = 'Consumable';
    public const NON_RENEWING_SUBSCRIPTION   = 'Non-Renewing Subscription';

    public static function from(string $value): string
    {
        $map = [
            self::AUTO_RENEWABLE_SUBSCRIPTION,
            self::NON_CONSUMABLE,
            self::CONSUMABLE,
            self::NON_RENEWING_SUBSCRIPTION,
        ];

        if (in_array($value, $map, true)) {
            return $value;
        }

        throw new AppleIapException("Unknown product type: {$value}");
    }

    public static function isSubscription(string $type): bool
    {
        return in_array($type, [
            self::AUTO_RENEWABLE_SUBSCRIPTION,
            self::NON_RENEWING_SUBSCRIPTION,
        ], true);
    }

    private function __construct()
    {
    }
}
