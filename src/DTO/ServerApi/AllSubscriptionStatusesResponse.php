<?php

namespace Kkxdev\AppleIap\DTO\ServerApi;

class AllSubscriptionStatusesResponse
{
    /**
     * @param SubscriptionGroupStatus[] $data
     */
    public function __construct(
        public string $appAppleId,
        public string $bundleId,
        public string $environment,
        public array $data,
    ) {
    }

    public function hasActiveSubscription(): bool
    {
        foreach ($this->data as $group) {
            foreach ($group->subscriptions as $sub) {
                if ($sub->isActive()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return SubscriptionStatus[]
     */
    public function allSubscriptions(): array
    {
        $all = [];
        foreach ($this->data as $group) {
            foreach ($group->subscriptions as $sub) {
                $all[] = $sub;
            }
        }

        return $all;
    }
}
