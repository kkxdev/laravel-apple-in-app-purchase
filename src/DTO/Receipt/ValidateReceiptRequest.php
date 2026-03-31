<?php

namespace Kkxdev\AppleIap\DTO\Receipt;

class ValidateReceiptRequest
{
    public function __construct(
        public string $receiptData,
        public ?string $sharedSecret = null,
        public bool $excludeOldTransactions = false,
    ) {
    }

    public function toArray(): array
    {
        $payload = [
            'receipt-data'              => $this->receiptData,
            'exclude-old-transactions'  => $this->excludeOldTransactions,
        ];

        if ($this->sharedSecret !== null) {
            $payload['password'] = $this->sharedSecret;
        }

        return $payload;
    }
}
