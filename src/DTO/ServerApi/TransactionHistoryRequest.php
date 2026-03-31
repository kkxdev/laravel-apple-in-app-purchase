<?php

namespace Kkxdev\AppleIap\DTO\ServerApi;

class TransactionHistoryRequest
{
    public function __construct(
        public ?string $revision = null,
        public ?int $startDate = null,
        public ?int $endDate = null,
        public ?array $productIds = null,
        public ?array $productTypes = null,
        public ?string $sort = null,
        public ?array $subscriptionGroupIdentifiers = null,
        public ?string $inAppOwnershipType = null,
        public ?bool $revoked = null,
    ) {
    }

    public function toQueryParams(): array
    {
        $params = [];

        if ($this->revision !== null)        $params['revision']                      = $this->revision;
        if ($this->startDate !== null)       $params['startDate']                     = $this->startDate;
        if ($this->endDate !== null)         $params['endDate']                       = $this->endDate;
        if ($this->sort !== null)            $params['sort']                          = $this->sort;
        if ($this->inAppOwnershipType !== null) $params['inAppOwnershipType']         = $this->inAppOwnershipType;
        if ($this->revoked !== null)         $params['revoked']                       = $this->revoked ? 'true' : 'false';

        if (!empty($this->productIds)) {
            foreach ($this->productIds as $id) {
                $params['productId'][] = $id;
            }
        }

        if (!empty($this->productTypes)) {
            foreach ($this->productTypes as $type) {
                $params['productType'][] = $type;
            }
        }

        if (!empty($this->subscriptionGroupIdentifiers)) {
            foreach ($this->subscriptionGroupIdentifiers as $id) {
                $params['subscriptionGroupIdentifier'][] = $id;
            }
        }

        return $params;
    }
}
