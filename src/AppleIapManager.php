<?php

namespace Kkxdev\AppleIap;

use Illuminate\Contracts\Events\Dispatcher;
use Kkxdev\AppleIap\Contracts\AppStoreServerApiInterface;
use Kkxdev\AppleIap\Contracts\JwsVerifierInterface;
use Kkxdev\AppleIap\Contracts\NotificationVerifierInterface;
use Kkxdev\AppleIap\Contracts\ReceiptValidatorInterface;
use Kkxdev\AppleIap\DTO\Notification\ServerNotification;
use Kkxdev\AppleIap\DTO\Receipt\ValidateReceiptRequest;
use Kkxdev\AppleIap\DTO\Receipt\ValidateReceiptResponse;
use Kkxdev\AppleIap\DTO\ServerApi\AllSubscriptionStatusesResponse;
use Kkxdev\AppleIap\DTO\ServerApi\ExtendRenewalDateRequest;
use Kkxdev\AppleIap\DTO\ServerApi\ExtendRenewalDateResponse;
use Kkxdev\AppleIap\DTO\ServerApi\RefundLookupResponse;
use Kkxdev\AppleIap\DTO\ServerApi\TransactionHistoryRequest;
use Kkxdev\AppleIap\DTO\ServerApi\TransactionHistoryResponse;
use Kkxdev\AppleIap\DTO\Transaction\JwsRenewalInfo;
use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;
use Kkxdev\AppleIap\Events;
use Kkxdev\AppleIap\Support\NotificationTypeResolver;

/**
 * Main entry point for all Apple IAP operations.
 * Accessible via the AppleIap facade.
 */
class AppleIapManager
{
    public function __construct(
        private ReceiptValidatorInterface $receiptValidator,
        private AppStoreServerApiInterface $serverApi,
        private JwsVerifierInterface $jwsVerifier,
        private NotificationVerifierInterface $notificationVerifier,
        private NotificationTypeResolver $notificationResolver,
        private Dispatcher $events,
        private array $config,
    ) {
    }

    // -------------------------------------------------------------------------
    // Legacy Receipt Validation
    // -------------------------------------------------------------------------

    /**
     * Validate an App Store receipt using the legacy verifyReceipt endpoint.
     *
     * @deprecated Apple deprecated verifyReceipt. Prefer App Store Server API for new integrations.
     * @param string|array $receiptData Base64-encoded receipt data or pre-decoded array
     */
    public function validateReceipt(
        string|array $receiptData,
        ?string $sharedSecret = null,
        bool $excludeOldTransactions = false
    ): ValidateReceiptResponse {
        $receiptString = is_array($receiptData) ? base64_encode(json_encode($receiptData)) : $receiptData;

        $request  = new ValidateReceiptRequest($receiptString, $sharedSecret, $excludeOldTransactions);
        $response = $this->receiptValidator->validate($request);

        $this->events->dispatch(new Events\ReceiptValidated($response));

        return $response;
    }

    // -------------------------------------------------------------------------
    // JWS / Transaction Verification
    // -------------------------------------------------------------------------

    /**
     * Verify and decode an Apple-signed JWS transaction token.
     * Fires TransactionVerified event on success.
     */
    public function decodeTransaction(string $signedTransaction): JwsTransaction
    {
        $payload     = $this->jwsVerifier->verify($signedTransaction);
        $transaction = JwsTransaction::fromArray($payload);

        $this->events->dispatch(new Events\TransactionVerified($transaction));

        return $transaction;
    }

    /**
     * Verify and decode an Apple-signed JWS renewal info token.
     */
    public function decodeRenewalInfo(string $signedRenewalInfo): JwsRenewalInfo
    {
        $payload = $this->jwsVerifier->verify($signedRenewalInfo);
        return JwsRenewalInfo::fromArray($payload);
    }

    // -------------------------------------------------------------------------
    // App Store Server Notifications v2
    // -------------------------------------------------------------------------

    /**
     * Verify, decode, and dispatch events for an App Store Server Notification.
     *
     * Always fires ServerNotificationReceived (catch-all) plus specific typed events.
     */
    public function processServerNotification(string $signedPayload): ServerNotification
    {
        $notification = $this->notificationVerifier->verify($signedPayload);

        // Always fire the catch-all event
        $this->events->dispatch(new Events\ServerNotificationReceived($notification));

        // Skip typed events for TEST notifications
        if ($notification->isTest()) {
            return $notification;
        }

        $this->dispatchTypedEvents($notification);

        return $notification;
    }

    private function dispatchTypedEvents(ServerNotification $notification): void
    {
        $eventClasses = $this->notificationResolver->resolve($notification);
        $data         = $notification->data;
        $transaction  = $data?->signedTransactionInfo;
        $renewalInfo  = $data?->signedRenewalInfo;

        foreach ($eventClasses as $eventClass) {
            $event = $this->buildEvent($eventClass, $notification, $transaction, $renewalInfo);
            if ($event !== null) {
                $this->events->dispatch($event);
            }
        }
    }

    private function buildEvent(
        string $eventClass,
        ServerNotification $notification,
        ?JwsTransaction $transaction,
        ?JwsRenewalInfo $renewalInfo
    ): ?object {
        return match ($eventClass) {
            Events\SubscriptionPurchased::class        => new Events\SubscriptionPurchased($notification, $transaction, $renewalInfo),
            Events\SubscriptionRenewed::class          => new Events\SubscriptionRenewed($notification, $transaction, $renewalInfo),
            Events\SubscriptionExpired::class          => new Events\SubscriptionExpired($notification, $transaction, $renewalInfo, $notification->subtype),
            Events\SubscriptionCancelled::class        => new Events\SubscriptionCancelled($notification, $transaction, $renewalInfo),
            Events\SubscriptionRevoked::class          => new Events\SubscriptionRevoked($notification, $transaction),
            Events\SubscriptionInBillingRetry::class   => new Events\SubscriptionInBillingRetry($notification, $transaction, $renewalInfo),
            Events\SubscriptionInGracePeriod::class    => new Events\SubscriptionInGracePeriod($notification, $transaction, $renewalInfo),
            Events\GracePeriodExpired::class           => new Events\GracePeriodExpired($notification, $transaction, $renewalInfo),
            Events\SubscriptionPlanChanged::class      => new Events\SubscriptionPlanChanged($notification, $transaction, $renewalInfo),
            Events\SubscriptionAutoRenewEnabled::class => new Events\SubscriptionAutoRenewEnabled($notification, $transaction, $renewalInfo),
            Events\SubscriptionAutoRenewDisabled::class => new Events\SubscriptionAutoRenewDisabled($notification, $transaction, $renewalInfo),
            Events\SubscriptionOfferRedeemed::class    => new Events\SubscriptionOfferRedeemed($notification, $transaction, $renewalInfo),
            Events\SubscriptionPriceIncrease::class    => new Events\SubscriptionPriceIncrease($notification, $transaction, $renewalInfo),
            Events\SubscriptionExpiredPriceIncrease::class => new Events\SubscriptionExpiredPriceIncrease($notification, $transaction, $renewalInfo),
            Events\ConsumablePurchased::class          => new Events\ConsumablePurchased($notification, $transaction),
            Events\NonConsumablePurchased::class       => new Events\NonConsumablePurchased($notification, $transaction),
            Events\NonRenewingSubscriptionPurchased::class => new Events\NonRenewingSubscriptionPurchased($notification, $transaction),
            Events\RefundIssued::class                 => new Events\RefundIssued($notification, $transaction),
            Events\RefundDeclined::class               => new Events\RefundDeclined($notification, $transaction),
            Events\RefundReversed::class               => new Events\RefundReversed($notification, $transaction),
            Events\ConsumptionRequest::class           => new Events\ConsumptionRequest($notification, $transaction),
            Events\RenewalExtension::class             => new Events\RenewalExtension($notification),
            Events\OneTimeChargePurchased::class       => new Events\OneTimeChargePurchased($notification, $transaction),
            default                                    => null,
        };
    }

    // -------------------------------------------------------------------------
    // App Store Server API (StoreKit 2)
    // -------------------------------------------------------------------------

    public function getTransactionHistory(
        string $originalTransactionId,
        ?TransactionHistoryRequest $params = null
    ): TransactionHistoryResponse {
        return $this->serverApi->getTransactionHistory($originalTransactionId, $params);
    }

    public function getTransactionHistoryV2(
        string $originalTransactionId,
        ?TransactionHistoryRequest $params = null
    ): TransactionHistoryResponse {
        return $this->serverApi->getTransactionHistoryV2($originalTransactionId, $params);
    }

    public function getAllSubscriptionStatuses(string $originalTransactionId): AllSubscriptionStatusesResponse
    {
        return $this->serverApi->getAllSubscriptionStatuses($originalTransactionId);
    }

    public function lookUpOrderId(string $orderId): TransactionHistoryResponse
    {
        return $this->serverApi->lookUpOrderId($orderId);
    }

    public function getRefundHistory(string $originalTransactionId): RefundLookupResponse
    {
        return $this->serverApi->getRefundHistory($originalTransactionId);
    }

    public function extendSubscriptionRenewalDate(
        string $originalTransactionId,
        ExtendRenewalDateRequest $request
    ): ExtendRenewalDateResponse {
        return $this->serverApi->extendSubscriptionRenewalDate($originalTransactionId, $request);
    }

    public function sendTestNotification(): string
    {
        return $this->serverApi->sendTestNotification();
    }

    public function getTestNotificationStatus(string $testNotificationToken): array
    {
        return $this->serverApi->getTestNotificationStatus($testNotificationToken);
    }

    // -------------------------------------------------------------------------
    // Direct sub-API access (power user path)
    // -------------------------------------------------------------------------

    public function receiptValidator(): ReceiptValidatorInterface
    {
        return $this->receiptValidator;
    }

    public function serverApi(): AppStoreServerApiInterface
    {
        return $this->serverApi;
    }

    public function jwsVerifier(): JwsVerifierInterface
    {
        return $this->jwsVerifier;
    }

    public function notificationVerifier(): NotificationVerifierInterface
    {
        return $this->notificationVerifier;
    }
}
