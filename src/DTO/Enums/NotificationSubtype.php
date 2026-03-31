<?php

namespace Kkxdev\AppleIap\DTO\Enums;

/**
 * App Store Server Notification v2 notification subtypes.
 */
final class NotificationSubtype
{
    public const INITIAL_BUY            = 'INITIAL_BUY';
    public const RESUBSCRIBE            = 'RESUBSCRIBE';
    public const DOWNGRADE              = 'DOWNGRADE';
    public const UPGRADE                = 'UPGRADE';
    public const AUTO_RENEW_ENABLED     = 'AUTO_RENEW_ENABLED';
    public const AUTO_RENEW_DISABLED    = 'AUTO_RENEW_DISABLED';
    public const VOLUNTARY              = 'VOLUNTARY';
    public const BILLING_RETRY          = 'BILLING_RETRY';
    public const PRICE_INCREASE         = 'PRICE_INCREASE';
    public const GRACE_PERIOD           = 'GRACE_PERIOD';
    public const PENDING                = 'PENDING';
    public const ACCEPTED               = 'ACCEPTED';
    public const PRODUCT_NOT_FOR_SALE   = 'PRODUCT_NOT_FOR_SALE';
    public const BILLING_RECOVERY       = 'BILLING_RECOVERY';
    public const SUMMARY                = 'SUMMARY';
    public const FAILURE                = 'FAILURE';

    private function __construct()
    {
    }
}
