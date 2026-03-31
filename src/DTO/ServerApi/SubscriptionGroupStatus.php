<?php

namespace Kkxdev\AppleIap\DTO\ServerApi;

class SubscriptionGroupStatus
{
    /**
     * @param SubscriptionStatus[] $subscriptions
     */
    public function __construct(
        public string $subscriptionGroupIdentifier,
        public array $subscriptions,
    ) {
    }
}
