<?php

namespace Kkxdev\AppleIap\Contracts;

use Kkxdev\AppleIap\DTO\Notification\ServerNotification;

interface NotificationVerifierInterface
{
    /**
     * Verify and decode an App Store Server Notification v2 signed payload.
     *
     * @throws \Kkxdev\AppleIap\Exceptions\NotificationVerificationException
     */
    public function verify(string $signedPayload): ServerNotification;

    /**
     * Check if a signed payload is valid without throwing on failure.
     */
    public function isValid(string $signedPayload): bool;
}
