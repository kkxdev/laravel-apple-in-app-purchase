<?php

namespace Kkxdev\AppleIap\Tests\Unit\Support;

use Kkxdev\AppleIap\DTO\PromotionalOfferSignature;
use Kkxdev\AppleIap\Exceptions\AppleIapException;
use Kkxdev\AppleIap\Support\PromotionalOfferSignatureGenerator;
use Kkxdev\AppleIap\Tests\TestCase;

class PromotionalOfferSignatureGeneratorTest extends TestCase
{
    private string $privateKey;
    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Generate a fresh P-256 key pair for each test run.
        $keyResource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);

        $privateKey = '';
        openssl_pkey_export($keyResource, $privateKey);
        $this->privateKey = $privateKey;

        $details         = openssl_pkey_get_details($keyResource);
        $this->publicKey = $details['key'];
    }

    private function makeGenerator(
        string $bundleId      = 'com.example.myapp',
        string $keyIdentifier = 'ABCD1234EF',
    ): PromotionalOfferSignatureGenerator {
        return new PromotionalOfferSignatureGenerator(
            bundleId:      $bundleId,
            keyIdentifier: $keyIdentifier,
            privateKey:    $this->privateKey,
        );
    }

    public function test_returns_promotional_offer_signature_dto(): void
    {
        $result = $this->makeGenerator()->generate(
            productIdentifier:   'com.example.myapp.pro.monthly',
            offerIdentifier:     'monthly_winback_50',
            applicationUsername: '7e3fb204-2a8a-4bd1-8a4f-1b3892cf1bda',
        );

        $this->assertInstanceOf(PromotionalOfferSignature::class, $result);
        $this->assertSame('ABCD1234EF', $result->keyIdentifier);
        $this->assertNotEmpty($result->nonce);
        $this->assertNotEmpty($result->signature);
        $this->assertGreaterThan(0, $result->timestamp);
    }

    public function test_nonce_is_lowercase_uuid_v4(): void
    {
        $result = $this->makeGenerator()->generate('com.example.pro', 'offer1');

        // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx (all lowercase)
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result->nonce,
        );
    }

    public function test_timestamp_is_in_milliseconds(): void
    {
        $before = (int) (microtime(true) * 1000);
        $result = $this->makeGenerator()->generate('com.example.pro', 'offer1');
        $after  = (int) (microtime(true) * 1000);

        $this->assertGreaterThanOrEqual($before, $result->timestamp);
        $this->assertLessThanOrEqual($after, $result->timestamp);
    }

    public function test_accepts_explicit_nonce_and_timestamp(): void
    {
        $nonce     = 'aaaabbbb-cccc-4ddd-8eee-ffffffffffff';
        $timestamp = 1742904277000;

        $result = $this->makeGenerator()->generate(
            productIdentifier:   'com.example.pro',
            offerIdentifier:     'offer1',
            applicationUsername: '',
            nonce:               $nonce,
            timestamp:           $timestamp,
        );

        $this->assertSame($nonce, $result->nonce);
        $this->assertSame($timestamp, $result->timestamp);
    }

    public function test_signature_is_valid_ecdsa_sha256(): void
    {
        $bundleId     = 'com.example.myapp';
        $keyId        = 'ABCD1234EF';
        $product      = 'com.example.pro.monthly';
        $offer        = 'monthly_winback_50';
        $appUsername  = 'user-uuid-1234';
        $nonce        = 'aaaabbbb-cccc-4ddd-8eee-ffffffffffff';
        $timestamp    = 1742904277000;

        $result = $this->makeGenerator($bundleId, $keyId)->generate(
            productIdentifier:   $product,
            offerIdentifier:     $offer,
            applicationUsername: $appUsername,
            nonce:               $nonce,
            timestamp:           $timestamp,
        );

        // Reconstruct the payload exactly as the generator does.
        $sep     = "\u{2063}";
        $payload = implode($sep, [$bundleId, $keyId, $product, $offer, $appUsername, $nonce, (string) $timestamp]);

        $rawSignature = base64_decode($result->signature);
        $publicKey    = openssl_pkey_get_public($this->publicKey);
        $verified     = openssl_verify($payload, $rawSignature, $publicKey, OPENSSL_ALGO_SHA256);

        $this->assertSame(1, $verified, 'Signature did not verify against the public key.');
    }

    public function test_empty_application_username_produces_double_separator(): void
    {
        // With an empty applicationUsername the payload contains two consecutive U+2063 chars.
        // Apple explicitly requires this — this test ensures we do not silently drop the field.
        $sep = "\u{2063}";

        $nonce     = 'aaaabbbb-cccc-4ddd-8eee-ffffffffffff';
        $timestamp = 1742904277000;

        $result = $this->makeGenerator()->generate(
            productIdentifier:   'com.example.pro',
            offerIdentifier:     'offer1',
            applicationUsername: '',           // empty — must NOT be omitted
            nonce:               $nonce,
            timestamp:           $timestamp,
        );

        // Verify the signature cryptographically (which implicitly validates the payload).
        $expectedPayload = implode($sep, [
            'com.example.myapp',
            'ABCD1234EF',
            'com.example.pro',
            'offer1',
            '',               // empty username — double separator in the string
            $nonce,
            (string) $timestamp,
        ]);

        $rawSignature = base64_decode($result->signature);
        $verified     = openssl_verify($expectedPayload, $rawSignature, $this->publicKey, OPENSSL_ALGO_SHA256);

        $this->assertSame(1, $verified);
    }

    public function test_each_call_generates_a_unique_nonce(): void
    {
        $gen    = $this->makeGenerator();
        $result1 = $gen->generate('com.example.pro', 'offer1');
        $result2 = $gen->generate('com.example.pro', 'offer1');

        $this->assertNotSame($result1->nonce, $result2->nonce);
    }

    public function test_to_array_returns_all_four_keys(): void
    {
        $result = $this->makeGenerator()->generate('com.example.pro', 'offer1');
        $array  = $result->toArray();

        $this->assertArrayHasKey('keyIdentifier', $array);
        $this->assertArrayHasKey('nonce', $array);
        $this->assertArrayHasKey('timestamp', $array);
        $this->assertArrayHasKey('signature', $array);
    }

    public function test_throws_on_invalid_private_key(): void
    {
        $generator = new PromotionalOfferSignatureGenerator(
            bundleId:      'com.example.myapp',
            keyIdentifier: 'ABCD1234EF',
            privateKey:    'not-a-valid-key',
        );

        $this->expectException(AppleIapException::class);
        $this->expectExceptionMessageMatches('/private key/i');

        $generator->generate('com.example.pro', 'offer1');
    }
}
