<?php

namespace Kkxdev\AppleIap\Events;

use Kkxdev\AppleIap\DTO\Notification\ServerNotification;
use Kkxdev\AppleIap\DTO\Transaction\JwsRenewalInfo;
use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;

class SubscriptionInGracePeriod
{
    public function __construct(
        public ServerNotification $notification,
        public ?JwsTransaction $transaction,
        public ?JwsRenewalInfo $renewalInfo,
    ) {
    }
}
