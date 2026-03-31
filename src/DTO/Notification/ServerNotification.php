<?php

namespace Kkxdev\AppleIap\DTO\Notification;

/**
 * Top-level App Store Server Notification v2 payload.
 *
 * @see https://developer.apple.com/documentation/appstoreservernotifications/responsebodyv2decodedpayload
 */
class ServerNotification
{
    public function __construct(
        public string $notificationType,
        public ?string $subtype,
        public string $notificationUUID,
        public string $notificationVersion,
        public ?NotificationData $data,
        public ?array $summary,
        public ?array $externalPurchaseToken,
        public int $signedDate,
        public string $version,
    ) {
    }

    public static function fromArray(array $payload, ?NotificationData $data): self
    {
        return new self(
            notificationType:    $payload['notificationType'] ?? '',
            subtype:             $payload['subtype'] ?? null,
            notificationUUID:    $payload['notificationUUID'] ?? '',
            notificationVersion: $payload['notificationVersion'] ?? '',
            data:                $data,
            summary:             $payload['summary'] ?? null,
            externalPurchaseToken: $payload['externalPurchaseToken'] ?? null,
            signedDate:          $payload['signedDate'] ?? 0,
            version:             $payload['version'] ?? '2.0',
        );
    }

    public function isTest(): bool
    {
        return $this->notificationType === 'TEST';
    }
}
