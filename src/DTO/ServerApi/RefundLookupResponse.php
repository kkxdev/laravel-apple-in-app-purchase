<?php

namespace Kkxdev\AppleIap\DTO\ServerApi;

use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;

class RefundLookupResponse
{
    /**
     * @param JwsTransaction[] $transactions
     */
    public function __construct(
        public bool $hasMore,
        public ?string $revision,
        public array $transactions,
    ) {
    }
}
