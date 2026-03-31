<?php

namespace Kkxdev\AppleIap\Events;

use Kkxdev\AppleIap\DTO\Notification\ServerNotification;

/**
 * Fired for every successfully verified App Store Server Notification.
 * Use this as a catch-all alongside specific typed events.
 */
class ServerNotificationReceived
{
    public function __construct(
        public ServerNotification $notification,
    ) {
    }
}
