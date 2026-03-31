<?php

namespace Kkxdev\AppleIap\Crypto;

use Kkxdev\AppleIap\Contracts\JwsVerifierInterface;
use Kkxdev\AppleIap\Contracts\NotificationVerifierInterface;
use Kkxdev\AppleIap\DTO\Notification\NotificationData;
use Kkxdev\AppleIap\DTO\Notification\ServerNotification;
use Kkxdev\AppleIap\DTO\Transaction\JwsRenewalInfo;
use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;
use Kkxdev\AppleIap\Exceptions\NotificationVerificationException;

class JwtNotificationVerifier implements NotificationVerifierInterface
{
    public function __construct(
        private JwsVerifierInterface $jwsVerifier,
    ) {
    }

    public function verify(string $signedPayload): ServerNotification
    {
        try {
            $payload = $this->jwsVerifier->verify($signedPayload);
        } catch (\Throwable $e) {
            throw new NotificationVerificationException(
                'Failed to verify server notification payload: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $this->buildNotification($payload);
    }

    public function isValid(string $signedPayload): bool
    {
        try {
            $this->verify($signedPayload);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildNotification(array $payload): ServerNotification
    {
        $rawData       = $payload['data'] ?? null;
        $notificationData = null;

        if (is_array($rawData)) {
            $transaction = null;
            $renewalInfo = null;

            if (!empty($rawData['signedTransactionInfo'])) {
                try {
                    $txPayload   = $this->jwsVerifier->verify($rawData['signedTransactionInfo']);
                    $transaction = JwsTransaction::fromArray($txPayload);
                } catch (\Throwable $e) {
                    throw new NotificationVerificationException(
                        'Failed to verify signedTransactionInfo within notification: ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }

            if (!empty($rawData['signedRenewalInfo'])) {
                try {
                    $renewalPayload = $this->jwsVerifier->verify($rawData['signedRenewalInfo']);
                    $renewalInfo    = JwsRenewalInfo::fromArray($renewalPayload);
                } catch (\Throwable $e) {
                    throw new NotificationVerificationException(
                        'Failed to verify signedRenewalInfo within notification: ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }

            $notificationData = NotificationData::fromArray($rawData, $transaction, $renewalInfo);
        }

        return ServerNotification::fromArray($payload, $notificationData);
    }
}
