<?php

namespace Kkxdev\AppleIap\Events;

use Kkxdev\AppleIap\DTO\Notification\ServerNotification;

class RenewalExtension
{
    public function __construct(
        public ServerNotification $notification,
    ) {
    }
}
