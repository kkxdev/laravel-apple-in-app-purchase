<?php

namespace Kkxdev\AppleIap\Support;

use Kkxdev\AppleIap\DTO\Enums\NotificationSubtype;
use Kkxdev\AppleIap\DTO\Enums\NotificationType;
use Kkxdev\AppleIap\DTO\Enums\ProductType;
use Kkxdev\AppleIap\DTO\Notification\ServerNotification;
use Kkxdev\AppleIap\Events;

/**
 * Maps a ServerNotification to zero or more specific event class names.
 *
 * Follows Apple's documented notificationType + subtype matrix.
 */
class NotificationTypeResolver
{
    /**
     * @return string[] Fully-qualified event class names to dispatch
     */
    public function resolve(ServerNotification $notification): array
    {
        $type    = $notification->notificationType;
        $subtype = $notification->subtype;
        $data    = $notification->data;

        return match ($type) {
            NotificationType::SUBSCRIBED => $this->resolveSubscribed($subtype, $data),

            NotificationType::DID_RENEW => [Events\SubscriptionRenewed::class],

            NotificationType::EXPIRED => $this->resolveExpired($subtype),

            NotificationType::DID_FAIL_TO_RENEW => $this->resolveFailedToRenew($data),

            NotificationType::GRACE_PERIOD_EXPIRED => [Events\GracePeriodExpired::class],

            NotificationType::DID_CHANGE_RENEWAL_STATUS => $this->resolveRenewalStatusChange($subtype),

            NotificationType::DID_CHANGE_RENEWAL_PREF => [Events\SubscriptionPlanChanged::class],

            NotificationType::OFFER_REDEEMED => [Events\SubscriptionOfferRedeemed::class],

            NotificationType::REFUND => [Events\RefundIssued::class],

            NotificationType::REFUND_DECLINED => [Events\RefundDeclined::class],

            NotificationType::REFUND_REVERSED => [Events\RefundReversed::class],

            NotificationType::REVOKE => [Events\SubscriptionRevoked::class],

            NotificationType::PRICE_INCREASE => [Events\SubscriptionPriceIncrease::class],

            NotificationType::CONSUMPTION_REQUEST => [Events\ConsumptionRequest::class],

            NotificationType::RENEWAL_EXTENSION => [Events\RenewalExtension::class],

            NotificationType::ONE_TIME_CHARGE => [Events\OneTimeChargePurchased::class],

            NotificationType::TEST, NotificationType::EXTERNAL_PURCHASE_TOKEN => [],

            default => [],
        };
    }

    private function resolveSubscribed(?string $subtype, ?object $data): array
    {
        $transaction = $data?->signedTransactionInfo;

        if ($transaction !== null) {
            return match ($transaction->type) {
                ProductType::CONSUMABLE => [Events\ConsumablePurchased::class],
                ProductType::NON_CONSUMABLE => [Events\NonConsumablePurchased::class],
                ProductType::NON_RENEWING_SUBSCRIPTION => [Events\NonRenewingSubscriptionPurchased::class],
                default => [Events\SubscriptionPurchased::class],
            };
        }

        // Fallback: treat all SUBSCRIBED as a subscription purchase
        return [Events\SubscriptionPurchased::class];
    }

    private function resolveExpired(?string $subtype): array
    {
        return match ($subtype) {
            NotificationSubtype::VOLUNTARY       => [Events\SubscriptionCancelled::class],
            NotificationSubtype::BILLING_RETRY   => [Events\SubscriptionExpired::class],
            NotificationSubtype::PRICE_INCREASE  => [Events\SubscriptionExpiredPriceIncrease::class],
            NotificationSubtype::PRODUCT_NOT_FOR_SALE => [Events\SubscriptionExpired::class],
            default                              => [Events\SubscriptionExpired::class],
        };
    }

    private function resolveFailedToRenew(?object $data): array
    {
        $renewalInfo = $data?->signedRenewalInfo;

        if ($renewalInfo !== null && $renewalInfo->isInGracePeriod()) {
            return [Events\SubscriptionInGracePeriod::class];
        }

        return [Events\SubscriptionInBillingRetry::class];
    }

    private function resolveRenewalStatusChange(?string $subtype): array
    {
        return match ($subtype) {
            NotificationSubtype::AUTO_RENEW_ENABLED  => [Events\SubscriptionAutoRenewEnabled::class],
            NotificationSubtype::AUTO_RENEW_DISABLED => [Events\SubscriptionAutoRenewDisabled::class],
            default                                  => [],
        };
    }
}
