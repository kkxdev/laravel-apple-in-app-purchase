<?php

namespace Kkxdev\AppleIap\Tests\Unit\Support;

use Kkxdev\AppleIap\DTO\Enums\NotificationSubtype;
use Kkxdev\AppleIap\DTO\Enums\NotificationType;
use Kkxdev\AppleIap\DTO\Notification\NotificationData;
use Kkxdev\AppleIap\DTO\Notification\ServerNotification;
use Kkxdev\AppleIap\DTO\Transaction\JwsRenewalInfo;
use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;
use Kkxdev\AppleIap\Events;
use Kkxdev\AppleIap\Support\NotificationTypeResolver;
use Kkxdev\AppleIap\Tests\TestCase;

class NotificationTypeResolverTest extends TestCase
{
    private NotificationTypeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new NotificationTypeResolver();
    }

    public function test_subscribed_auto_renewable_resolves_to_subscription_purchased(): void
    {
        $notification = $this->makeNotification(NotificationType::SUBSCRIBED, null, 'Auto-Renewable Subscription');

        $events = $this->resolver->resolve($notification);

        $this->assertContains(Events\SubscriptionPurchased::class, $events);
    }

    public function test_subscribed_consumable_resolves_to_consumable_purchased(): void
    {
        $notification = $this->makeNotification(NotificationType::SUBSCRIBED, null, 'Consumable');

        $events = $this->resolver->resolve($notification);

        $this->assertContains(Events\ConsumablePurchased::class, $events);
    }

    public function test_subscribed_non_consumable_resolves_to_non_consumable_purchased(): void
    {
        $notification = $this->makeNotification(NotificationType::SUBSCRIBED, null, 'Non-Consumable');

        $events = $this->resolver->resolve($notification);

        $this->assertContains(Events\NonConsumablePurchased::class, $events);
    }

    public function test_did_renew_resolves_to_subscription_renewed(): void
    {
        $notification = $this->makeNotification(NotificationType::DID_RENEW);

        $events = $this->resolver->resolve($notification);

        $this->assertContains(Events\SubscriptionRenewed::class, $events);
    }

    public function test_expired_voluntary_resolves_to_subscription_cancelled(): void
    {
        $notification = $this->makeNotification(NotificationType::EXPIRED, NotificationSubtype::VOLUNTARY);

        $events = $this->resolver->resolve($notification);

        $this->assertContains(Events\SubscriptionCancelled::class, $events);
    }

    public function test_expired_billing_retry_resolves_to_subscription_expired(): void
    {
        $notification = $this->makeNotification(NotificationType::EXPIRED, NotificationSubtype::BILLING_RETRY);

        $events = $this->resolver->resolve($notification);

        $this->assertContains(Events\SubscriptionExpired::class, $events);
    }

    public function test_refund_resolves_to_refund_issued(): void
    {
        $notification = $this->makeNotification(NotificationType::REFUND);

        $events = $this->resolver->resolve($notification);

        $this->assertContains(Events\RefundIssued::class, $events);
    }

    public function test_revoke_resolves_to_subscription_revoked(): void
    {
        $notification = $this->makeNotification(NotificationType::REVOKE);

        $events = $this->resolver->resolve($notification);

        $this->assertContains(Events\SubscriptionRevoked::class, $events);
    }

    public function test_test_notification_resolves_to_empty(): void
    {
        $notification = $this->makeNotification(NotificationType::TEST);

        $events = $this->resolver->resolve($notification);

        $this->assertEmpty($events);
    }

    public function test_did_change_renewal_status_auto_renew_disabled(): void
    {
        $notification = $this->makeNotification(
            NotificationType::DID_CHANGE_RENEWAL_STATUS,
            NotificationSubtype::AUTO_RENEW_DISABLED
        );

        $events = $this->resolver->resolve($notification);

        $this->assertContains(Events\SubscriptionAutoRenewDisabled::class, $events);
    }

    private function makeNotification(
        string $type,
        ?string $subtype = null,
        ?string $productType = null,
    ): ServerNotification {
        $transaction = null;
        $renewalInfo = null;

        if ($productType !== null) {
            $transaction = JwsTransaction::fromArray([
                'transactionId'         => 'tx-001',
                'originalTransactionId' => 'orig-001',
                'bundleId'              => 'com.example.app',
                'productId'             => 'test-product',
                'purchaseDate'          => time() * 1000,
                'originalPurchaseDate'  => time() * 1000,
                'quantity'              => 1,
                'type'                  => $productType,
                'inAppOwnershipType'    => 'PURCHASED',
                'signedDate'            => time() * 1000,
            ]);
        }

        $data = new NotificationData(
            appAppleId:            null,
            bundleId:              'com.example.app',
            bundleVersion:         '1.0',
            environment:           'Sandbox',
            signedTransactionInfo: $transaction,
            signedRenewalInfo:     $renewalInfo,
            status:                null,
        );

        return new ServerNotification(
            notificationType:    $type,
            subtype:             $subtype,
            notificationUUID:    'uuid-001',
            notificationVersion: '2.0',
            data:                $data,
            summary:             null,
            externalPurchaseToken: null,
            signedDate:          time() * 1000,
            version:             '2.0',
        );
    }
}
