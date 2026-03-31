<?php

namespace Kkxdev\AppleIap\DTO\Receipt;

class ValidateReceiptResponse
{
    /**
     * @param InAppPurchase[] $latestReceiptInfo
     * @param InAppPurchase[] $inApp
     */
    public function __construct(
        public int $status,
        public string $environment,
        public ?string $latestReceipt,
        public array $latestReceiptInfo,
        public array $inApp,
        public ?array $pendingRenewalInfo,
        public bool $isRetryable,
    ) {
    }

    public static function fromArray(array $data, string $environment): self
    {
        $latestReceiptInfo = array_map(
            static fn (array $item) => InAppPurchase::fromArray($item),
            $data['latest_receipt_info'] ?? []
        );

        $inApp = array_map(
            static fn (array $item) => InAppPurchase::fromArray($item),
            $data['receipt']['in_app'] ?? []
        );

        return new self(
            status:             (int) ($data['status'] ?? 0),
            environment:        $environment,
            latestReceipt:      $data['latest_receipt'] ?? null,
            latestReceiptInfo:  $latestReceiptInfo,
            inApp:              $inApp,
            pendingRenewalInfo: $data['pending_renewal_info'] ?? null,
            isRetryable:        (bool) ($data['is-retryable'] ?? false),
        );
    }

    public function isValid(): bool
    {
        return $this->status === 0;
    }

    public function isSandbox(): bool
    {
        return $this->environment === 'Sandbox';
    }

    /**
     * Get the most recent in-app purchase for a given product ID.
     */
    public function latestPurchaseFor(string $productId): ?InAppPurchase
    {
        $purchases = array_filter(
            $this->latestReceiptInfo,
            static fn (InAppPurchase $p) => $p->productId === $productId
        );

        if (empty($purchases)) {
            return null;
        }

        usort($purchases, static fn ($a, $b) => (int) $b->purchaseDateMs <=> (int) $a->purchaseDateMs);

        return $purchases[0];
    }
}
