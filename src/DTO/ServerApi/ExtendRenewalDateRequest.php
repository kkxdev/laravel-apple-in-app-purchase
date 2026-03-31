<?php

namespace Kkxdev\AppleIap\DTO\ServerApi;

class ExtendRenewalDateRequest
{
    /**
     * @param int $extendByDays  Number of days to extend (1-90)
     * @param int $extendReasonCode  0=other, 1=customer satisfaction issue, 2=other issue, 3=service issue, 4=app functionality issue
     * @param string $requestIdentifier  Unique identifier for idempotency
     * @param string|null $productId  Specific product ID (null = all)
     */
    public function __construct(
        public int $extendByDays,
        public int $extendReasonCode,
        public string $requestIdentifier,
        public ?string $productId = null,
    ) {
    }

    public function toArray(): array
    {
        $data = [
            'extendByDays'      => $this->extendByDays,
            'extendReasonCode'  => $this->extendReasonCode,
            'requestIdentifier' => $this->requestIdentifier,
        ];

        if ($this->productId !== null) {
            $data['productId'] = $this->productId;
        }

        return $data;
    }
}
