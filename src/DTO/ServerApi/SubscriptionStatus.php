<?php

namespace Kkxdev\AppleIap\DTO\ServerApi;

use Kkxdev\AppleIap\DTO\Transaction\JwsRenewalInfo;
use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;

class SubscriptionStatus
{
    public function __construct(
        public int $status,
        public ?JwsRenewalInfo $renewalInfo,
        public ?JwsTransaction $transactionInfo,
    ) {
    }

    /**
     * Apple subscription status codes:
     * 1 = active, 2 = expired, 3 = billing retry, 4 = grace period, 5 = revoked
     */
    public function isActive(): bool
    {
        return $this->status === 1;
    }

    public function isExpired(): bool
    {
        return $this->status === 2;
    }

    public function isInBillingRetry(): bool
    {
        return $this->status === 3;
    }

    public function isInGracePeriod(): bool
    {
        return $this->status === 4;
    }

    public function isRevoked(): bool
    {
        return $this->status === 5;
    }
}
