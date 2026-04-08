<?php

namespace Kkxdev\AppleIap\DTO\Transaction;

/**
 * Decoded JWS transaction payload from Apple.
 *
 * @see https://developer.apple.com/documentation/appstoreserverapi/jwstransactiondecodedpayload
 */
class JwsTransaction
{
    public function __construct(
        public string $transactionId,
        public string $originalTransactionId,
        public string $bundleId,
        public string $productId,
        public ?string $subscriptionGroupIdentifier,
        public int $purchaseDate,
        public int $originalPurchaseDate,
        public ?int $expiresDate,
        public int $quantity,
        public string $type,
        public ?string $appAccountToken,
        public string $inAppOwnershipType,
        public int $signedDate,
        public ?string $environment,
        public ?string $transactionReason,
        public ?string $storefront,
        public ?string $storefrontId,
        public ?int $price,
        public ?string $currency,
        public ?string $offerDiscountType,
        public ?string $offerIdentifier,
        public ?int $offerType,
        public ?int $revocationDate,
        public ?string $revocationReason,
        public bool $isUpgraded = false,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            transactionId:                $data['transactionId'] ?? '',
            originalTransactionId:        $data['originalTransactionId'] ?? '',
            bundleId:                     $data['bundleId'] ?? '',
            productId:                    $data['productId'] ?? '',
            subscriptionGroupIdentifier:  $data['subscriptionGroupIdentifier'] ?? null,
            purchaseDate:                 $data['purchaseDate'] ?? 0,
            originalPurchaseDate:         $data['originalPurchaseDate'] ?? 0,
            expiresDate:                  $data['expiresDate'] ?? null,
            quantity:                     $data['quantity'] ?? 1,
            type:                         $data['type'] ?? '',
            appAccountToken:              $data['appAccountToken'] ?? null,
            inAppOwnershipType:           $data['inAppOwnershipType'] ?? '',
            signedDate:                   $data['signedDate'] ?? 0,
            environment:                  $data['environment'] ?? null,
            transactionReason:            $data['transactionReason'] ?? null,
            storefront:                   $data['storefront'] ?? null,
            storefrontId:                 $data['storefrontId'] ?? null,
            price:                        $data['price'] ?? null,
            currency:                     $data['currency'] ?? null,
            offerDiscountType:            $data['offerDiscountType'] ?? null,
            offerIdentifier:              $data['offerIdentifier'] ?? null,
            offerType:                    $data['offerType'] ?? null,
            revocationDate:               $data['revocationDate'] ?? null,
            revocationReason:             $data['revocationReason'] ?? null,
            isUpgraded:                   (bool) ($data['isUpgraded'] ?? false),
        );
    }

    public function matchesBundleId(string $bundleId): bool
    {
        return $this->bundleId === $bundleId;
    }

    public function isExpired(): bool
    {
        return $this->expiresDate !== null && $this->expiresDate < (time() * 1000);
    }

    public function isRevoked(): bool
    {
        return $this->revocationDate !== null;
    }

    public function isSandbox(): bool
    {
        return $this->environment === 'Sandbox';
    }

    public function purchaseDateAsDateTime(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp((int) ($this->purchaseDate / 1000));
    }

    public function expiresDateAsDateTime(): ?\DateTimeImmutable
    {
        if ($this->expiresDate === null) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int) ($this->expiresDate / 1000));
    }
}
