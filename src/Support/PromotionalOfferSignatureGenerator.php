<?php

namespace Kkxdev\AppleIap\Support;

use Kkxdev\AppleIap\DTO\PromotionalOfferSignature;
use Kkxdev\AppleIap\Exceptions\AppleIapException;

/**
 * Generates a server-side promotional offer signature for Apple IAP.
 *
 * Apple verifies this signature when the iOS app calls:
 *   - StoreKit 2 → Product.purchase(options: [.promotionalOffer(…)])
 *   - StoreKit 1 → SKPaymentQueue.add(payment with SKPaymentDiscount)
 *
 * Payload format (fields joined by U+2063 INVISIBLE SEPARATOR):
 *   appBundleID · keyIdentifier · productIdentifier · offerIdentifier · applicationUsername · nonce · timestamp
 *
 * The payload is signed with ECDSA-SHA256 using the App Store Connect subscription
 * key (.p8 file). The DER-encoded signature is then Base64-encoded.
 *
 * @see https://developer.apple.com/documentation/storekit/generating-a-promotional-offer-signature-on-the-server
 */
class PromotionalOfferSignatureGenerator
{
    /**
     * Unicode INVISIBLE SEPARATOR (U+2063) — the exact delimiter Apple requires.
     * Do NOT replace with any other character, space, or null byte.
     */
    private const SEPARATOR = "\u{2063}";

    public function __construct(
        private string $bundleId,
        private string $keyIdentifier,
        private string $privateKey,
    ) {
    }

    /**
     * Generate a promotional offer signature.
     *
     * @param string      $productIdentifier  The subscription product ID (e.g. "com.example.pro.monthly").
     * @param string      $offerIdentifier    The promotional offer code set in App Store Connect.
     * @param string      $applicationUsername Your internal user token (appAccountToken UUID or empty string).
     *                                         Pass an empty string when not used — never pass null.
     * @param string|null $nonce              Lowercase UUID v4. Auto-generated when null.
     *                                         Generate a new nonce per request; Apple rejects duplicates.
     * @param int|null    $timestamp          Unix epoch in milliseconds. Defaults to now.
     *                                         Signatures expire after 24 hours.
     *
     * @throws AppleIapException When the private key is invalid or signing fails.
     */
    public function generate(
        string  $productIdentifier,
        string  $offerIdentifier,
        string  $applicationUsername = '',
        ?string $nonce = null,
        ?int    $timestamp = null,
    ): PromotionalOfferSignature {
        $nonce     = $nonce     ?? $this->generateNonce();
        $timestamp = $timestamp ?? $this->nowMilliseconds();

        $payload = implode(self::SEPARATOR, [
            $this->bundleId,
            $this->keyIdentifier,
            $productIdentifier,
            $offerIdentifier,
            $applicationUsername,   // must be empty string, never null
            $nonce,                 // must be lowercase
            (string) $timestamp,
        ]);

        $privateKeyResource = openssl_pkey_get_private($this->privateKey);

        if ($privateKeyResource === false) {
            throw new AppleIapException(
                'Failed to load the promotional offer private key. '
                . 'Ensure the key is a valid PKCS#8 PEM-encoded EC key (.p8 from App Store Connect). '
                . 'OpenSSL error: ' . openssl_error_string()
            );
        }

        $rawSignature = '';
        $success = openssl_sign($payload, $rawSignature, $privateKeyResource, OPENSSL_ALGO_SHA256);

        if (!$success) {
            throw new AppleIapException(
                'Failed to sign the promotional offer payload. '
                . 'OpenSSL error: ' . openssl_error_string()
            );
        }

        // openssl_sign with an EC key produces a DER-encoded ASN.1 SEQUENCE.
        // Base64-encode it (standard alphabet, not base64url) — that is what Apple expects.
        return new PromotionalOfferSignature(
            keyIdentifier: $this->keyIdentifier,
            nonce:         $nonce,
            timestamp:     $timestamp,
            signature:     base64_encode($rawSignature),
        );
    }

    /**
     * Generate a fresh lowercase UUID v4 without any external package.
     *
     * Apple requires the nonce to be a *lowercase* UUID string.
     * Standard PHP uuid functions may return uppercase — this method always returns lowercase.
     */
    private function generateNonce(): string
    {
        $bytes = random_bytes(16);

        // Set version (4) in the 7th byte: 0100xxxx
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);

        // Set RFC 4122 variant (10xx) in the 9th byte
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * Return the current Unix timestamp in milliseconds.
     */
    private function nowMilliseconds(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
