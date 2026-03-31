<?php

namespace Kkxdev\AppleIap\DTO\Receipt;

/**
 * A single in-app purchase record from a legacy receipt response.
 */
class InAppPurchase
{
    public function __construct(
        public string $productId,
        public string $transactionId,
        public string $originalTransactionId,
        public string $purchaseDateMs,
        public string $originalPurchaseDateMs,
        public ?string $expiresDateMs,
        public ?string $cancellationDateMs,
        public ?string $cancellationReason,
        public int $quantity,
        public bool $isTrialPeriod,
        public bool $isInIntroOfferPeriod,
        public ?string $webOrderLineItemId,
        public ?string $subscriptionGroupIdentifier,
        public ?string $inAppOwnershipType,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            productId:                   $data['product_id'] ?? '',
            transactionId:               $data['transaction_id'] ?? '',
            originalTransactionId:       $data['original_transaction_id'] ?? '',
            purchaseDateMs:              $data['purchase_date_ms'] ?? '0',
            originalPurchaseDateMs:      $data['original_purchase_date_ms'] ?? '0',
            expiresDateMs:               $data['expires_date_ms'] ?? null,
            cancellationDateMs:          $data['cancellation_date_ms'] ?? null,
            cancellationReason:          $data['cancellation_reason'] ?? null,
            quantity:                    (int) ($data['quantity'] ?? 1),
            isTrialPeriod:               filter_var($data['is_trial_period'] ?? false, FILTER_VALIDATE_BOOLEAN),
            isInIntroOfferPeriod:        filter_var($data['is_in_intro_offer_period'] ?? false, FILTER_VALIDATE_BOOLEAN),
            webOrderLineItemId:          $data['web_order_line_item_id'] ?? null,
            subscriptionGroupIdentifier: $data['subscription_group_identifier'] ?? null,
            inAppOwnershipType:          $data['in_app_ownership_type'] ?? null,
        );
    }

    public function isExpired(): bool
    {
        return $this->expiresDateMs !== null && (int) $this->expiresDateMs < (time() * 1000);
    }

    public function isCancelled(): bool
    {
        return $this->cancellationDateMs !== null;
    }

    public function purchaseDateAsDateTime(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp((int) ((int) $this->purchaseDateMs / 1000));
    }

    public function expiresDateAsDateTime(): ?\DateTimeImmutable
    {
        if ($this->expiresDateMs === null) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int) ((int) $this->expiresDateMs / 1000));
    }
}
