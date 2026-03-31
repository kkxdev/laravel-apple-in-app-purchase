<?php

namespace Kkxdev\AppleIap\DTO\ServerApi;

class ExtendRenewalDateResponse
{
    public function __construct(
        public string $originalTransactionId,
        public string $webOrderLineItemId,
        public bool $success,
        public ?int $effectiveDate,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            originalTransactionId: $data['originalTransactionId'] ?? '',
            webOrderLineItemId:    $data['webOrderLineItemId'] ?? '',
            success:               (bool) ($data['success'] ?? false),
            effectiveDate:         $data['effectiveDate'] ?? null,
        );
    }
}
