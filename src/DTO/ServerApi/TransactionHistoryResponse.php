<?php

namespace Kkxdev\AppleIap\DTO\ServerApi;

use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;

class TransactionHistoryResponse
{
    /**
     * @param JwsTransaction[] $transactions
     */
    public function __construct(
        public string $appAppleId,
        public string $bundleId,
        public string $environment,
        public bool $hasMore,
        public ?string $revision,
        public array $transactions,
    ) {
    }

    public static function fromArray(array $data, array $decodedTransactions): self
    {
        return new self(
            appAppleId:    $data['appAppleId'] ?? '',
            bundleId:      $data['bundleId'] ?? '',
            environment:   $data['environment'] ?? '',
            hasMore:       (bool) ($data['hasMore'] ?? false),
            revision:      $data['revision'] ?? null,
            transactions:  $decodedTransactions,
        );
    }
}
