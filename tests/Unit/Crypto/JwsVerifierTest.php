<?php

namespace Kkxdev\AppleIap\Tests\Unit\Crypto;

use Kkxdev\AppleIap\Crypto\JwsVerifier;
use Kkxdev\AppleIap\Exceptions\JwsVerificationException;
use Kkxdev\AppleIap\Tests\TestCase;

class JwsVerifierTest extends TestCase
{
    private JwsVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new JwsVerifier();
    }

    public function test_split_invalid_token_throws(): void
    {
        $this->expectException(JwsVerificationException::class);
        $this->expectExceptionMessage('Invalid JWS token format');

        $this->verifier->verify('not.a.valid.jws.token.here');
    }

    public function test_token_missing_x5c_throws(): void
    {
        $this->expectException(JwsVerificationException::class);

        // Header without x5c
        $header    = base64_encode(json_encode(['alg' => 'ES256']));
        $payload   = base64_encode(json_encode(['sub' => 'test']));
        $signature = base64_encode('fakesig');

        $this->verifier->verify("{$header}.{$payload}.{$signature}");
    }

    public function test_unsupported_algorithm_throws(): void
    {
        $this->expectException(JwsVerificationException::class);
        $this->expectExceptionMessage('Unsupported JWS algorithm');

        $header    = $this->base64url(json_encode(['alg' => 'RS256', 'x5c' => ['cert']]));
        $payload   = $this->base64url(json_encode(['sub' => 'test']));
        $signature = $this->base64url('fakesig');

        $this->verifier->verify("{$header}.{$payload}.{$signature}");
    }

    public function test_decode_without_verification_returns_payload(): void
    {
        $expectedPayload = ['transactionId' => '123', 'productId' => 'com.example.product'];

        $header    = $this->base64url(json_encode(['alg' => 'ES256']));
        $payload   = $this->base64url(json_encode($expectedPayload));
        $signature = $this->base64url('fakesig');

        $result = $this->verifier->decodeWithoutVerification("{$header}.{$payload}.{$signature}");

        $this->assertSame($expectedPayload, $result);
    }

    public function test_decode_without_verification_throws_on_invalid_format(): void
    {
        $this->expectException(JwsVerificationException::class);

        $this->verifier->decodeWithoutVerification('invalid');
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
