<?php

namespace Kkxdev\AppleIap\DTO\Notification;

use Kkxdev\AppleIap\DTO\Transaction\JwsRenewalInfo;
use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;

class NotificationData
{
    public function __construct(
        public ?int $appAppleId,
        public string $bundleId,
        public string $bundleVersion,
        public string $environment,
        public ?JwsTransaction $signedTransactionInfo,
        public ?JwsRenewalInfo $signedRenewalInfo,
        public ?int $status,
    ) {
    }

    public static function fromArray(array $data, ?JwsTransaction $transaction, ?JwsRenewalInfo $renewalInfo): self
    {
        return new self(
            appAppleId:          $data['appAppleId'] ?? null,
            bundleId:            $data['bundleId'] ?? '',
            bundleVersion:       $data['bundleVersion'] ?? '',
            environment:         $data['environment'] ?? '',
            signedTransactionInfo: $transaction,
            signedRenewalInfo:   $renewalInfo,
            status:              $data['status'] ?? null,
        );
    }
}
