<?php

namespace Kkxdev\AppleIap\DTO\Transaction;

/**
 * Decoded JWS renewal info payload from Apple.
 *
 * @see https://developer.apple.com/documentation/appstoreserverapi/jwsrenewalinfodecodedpayload
 */
class JwsRenewalInfo
{
    public function __construct(
        public string $originalTransactionId,
        public string $productId,
        public string $autoRenewProductId,
        public int $autoRenewStatus,
        public bool $isInBillingRetryPeriod,
        public ?int $gracePeriodExpiresDate,
        public ?string $offerIdentifier,
        public ?int $offerType,
        public ?int $priceConsentStatus,
        public ?int $renewalDate,
        public ?int $renewalPrice,
        public ?string $currency,
        public int $signedDate,
        public ?string $environment,
        public ?string $recentSubscriptionStartDate,
        public ?int $expirationIntent,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            originalTransactionId:       $data['originalTransactionId'] ?? '',
            productId:                   $data['productId'] ?? '',
            autoRenewProductId:          $data['autoRenewProductId'] ?? '',
            autoRenewStatus:             $data['autoRenewStatus'] ?? 0,
            isInBillingRetryPeriod:      (bool) ($data['isInBillingRetryPeriod'] ?? false),
            gracePeriodExpiresDate:      $data['gracePeriodExpiresDate'] ?? null,
            offerIdentifier:             $data['offerIdentifier'] ?? null,
            offerType:                   $data['offerType'] ?? null,
            priceConsentStatus:          $data['priceConsentStatus'] ?? null,
            renewalDate:                 $data['renewalDate'] ?? null,
            renewalPrice:                $data['renewalPrice'] ?? null,
            currency:                    $data['currency'] ?? null,
            signedDate:                  $data['signedDate'] ?? 0,
            environment:                 $data['environment'] ?? null,
            recentSubscriptionStartDate: $data['recentSubscriptionStartDate'] ?? null,
            expirationIntent:            $data['expirationIntent'] ?? null,
        );
    }

    public function willAutoRenew(): bool
    {
        return $this->autoRenewStatus === 1;
    }

    public function isInGracePeriod(): bool
    {
        return $this->gracePeriodExpiresDate !== null
            && $this->gracePeriodExpiresDate > (time() * 1000);
    }

    public function gracePeriodExpiresDateAsDateTime(): ?\DateTimeImmutable
    {
        if ($this->gracePeriodExpiresDate === null) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int) ($this->gracePeriodExpiresDate / 1000));
    }
}
