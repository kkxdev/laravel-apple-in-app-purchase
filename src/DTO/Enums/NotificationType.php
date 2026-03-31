<?php

namespace Kkxdev\AppleIap\DTO\Enums;

/**
 * App Store Server Notification v2 notification types.
 */
final class NotificationType
{
    public const SUBSCRIBED                = 'SUBSCRIBED';
    public const DID_RENEW                 = 'DID_RENEW';
    public const EXPIRED                   = 'EXPIRED';
    public const DID_FAIL_TO_RENEW         = 'DID_FAIL_TO_RENEW';
    public const GRACE_PERIOD_EXPIRED      = 'GRACE_PERIOD_EXPIRED';
    public const DID_CHANGE_RENEWAL_STATUS = 'DID_CHANGE_RENEWAL_STATUS';
    public const DID_CHANGE_RENEWAL_PREF   = 'DID_CHANGE_RENEWAL_PREF';
    public const OFFER_REDEEMED            = 'OFFER_REDEEMED';
    public const REFUND                    = 'REFUND';
    public const REFUND_DECLINED           = 'REFUND_DECLINED';
    public const REFUND_REVERSED           = 'REFUND_REVERSED';
    public const CONSUMPTION_REQUEST       = 'CONSUMPTION_REQUEST';
    public const PRICE_INCREASE            = 'PRICE_INCREASE';
    public const RENEWAL_EXTENSION         = 'RENEWAL_EXTENSION';
    public const REVOKE                    = 'REVOKE';
    public const TEST                      = 'TEST';
    public const EXTERNAL_PURCHASE_TOKEN   = 'EXTERNAL_PURCHASE_TOKEN';
    public const ONE_TIME_CHARGE           = 'ONE_TIME_CHARGE';

    private function __construct()
    {
    }
}
