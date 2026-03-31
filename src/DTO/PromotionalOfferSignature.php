<?php

namespace Kkxdev\AppleIap\DTO;

/**
 * The payload your server returns to the iOS app so it can call
 * SKPaymentDiscount / Product.PurchaseOption.promotionalOffer(…).
 *
 * All four fields are required by the StoreKit client.
 *
 * @see https://developer.apple.com/documentation/storekit/generating-a-promotional-offer-signature-on-the-server
 */
class PromotionalOfferSignature
{
    public function __construct(
        /** The Key ID that was used to sign (matches the keyIdentifier you give to StoreKit). */
        public string $keyIdentifier,

        /** Lowercase UUID v4 generated server-side. Each signature request needs a fresh nonce. */
        public string $nonce,

        /** Unix epoch in milliseconds. Signatures are valid for 24 hours. */
        public int $timestamp,

        /** Base64-encoded DER-encoded ECDSA-SHA256 signature. */
        public string $signature,
    ) {
    }

    /**
     * Serialise to an array suitable for a JSON API response.
     *
     * @return array{keyIdentifier: string, nonce: string, timestamp: int, signature: string}
     */
    public function toArray(): array
    {
        return [
            'keyIdentifier' => $this->keyIdentifier,
            'nonce'         => $this->nonce,
            'timestamp'     => $this->timestamp,
            'signature'     => $this->signature,
        ];
    }
}
