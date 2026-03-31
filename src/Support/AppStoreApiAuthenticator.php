<?php

namespace Kkxdev\AppleIap\Support;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Kkxdev\AppleIap\Exceptions\AppleIapException;

/**
 * Generates and caches the ES256-signed JWT Bearer token for App Store Server API authentication.
 *
 * @see https://developer.apple.com/documentation/appstoreserverapi/generating_tokens_for_api_requests
 */
class AppStoreApiAuthenticator
{
    private const CACHE_KEY = 'apple-iap:api-token';
    private const TTL = 3600;
    private const REFRESH_BEFORE = 60;

    public function __construct(
        private array $credentials,
        private CacheRepository $cache,
    ) {
    }

    public function getBearerToken(): string
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['token'], $cached['expires_at'])) {
            if ($cached['expires_at'] > time() + self::REFRESH_BEFORE) {
                return $cached['token'];
            }
        }

        return $this->generateAndCache();
    }

    private function generateAndCache(): string
    {
        $token = $this->generateToken();

        $this->cache->put(self::CACHE_KEY, [
            'token'      => $token,
            'expires_at' => time() + self::TTL,
        ], self::TTL);

        return $token;
    }

    private function generateToken(): string
    {
        $keyId    = $this->credentials['key_id'] ?? null;
        $issuerId = $this->credentials['issuer_id'] ?? null;
        $bundleId = $this->credentials['bundle_id'] ?? null;

        if (!$keyId || !$issuerId || !$bundleId) {
            throw new AppleIapException(
                'App Store Server API requires key_id, issuer_id, and bundle_id credentials. '
                . 'Check your apple-iap config.'
            );
        }

        $privateKey = $this->loadPrivateKey();

        $now = time();

        $payload = [
            'iss' => $issuerId,
            'iat' => $now,
            'exp' => $now + self::TTL,
            'aud' => 'appstoreconnect-v1',
            'bid' => $bundleId,
        ];

        $header = [
            'kid' => $keyId,
            'typ' => 'JWT',
        ];

        return JWT::encode($payload, $privateKey, 'ES256', $keyId, $header);
    }

    private function loadPrivateKey(): string
    {
        $keyContents = $this->credentials['private_key'] ?? null;

        if (!$keyContents) {
            $keyPath = $this->credentials['private_key_path'] ?? null;

            if (!$keyPath) {
                throw new AppleIapException(
                    'App Store Server API requires either private_key or private_key_path in credentials config.'
                );
            }

            if (!file_exists($keyPath)) {
                throw new AppleIapException("Apple IAP private key file not found at: {$keyPath}");
            }

            $keyContents = file_get_contents($keyPath);
        }

        if (!$keyContents) {
            throw new AppleIapException('Apple IAP private key is empty.');
        }

        return $keyContents;
    }

    /**
     * Flush the cached token (useful in tests or after key rotation).
     */
    public function forgetCachedToken(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
