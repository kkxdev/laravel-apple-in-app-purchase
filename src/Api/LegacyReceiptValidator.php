<?php

namespace Kkxdev\AppleIap\Api;

use Illuminate\Http\Client\Factory as HttpFactory;
use Kkxdev\AppleIap\Contracts\ReceiptValidatorInterface;
use Kkxdev\AppleIap\DTO\Receipt\ValidateReceiptRequest;
use Kkxdev\AppleIap\DTO\Receipt\ValidateReceiptResponse;
use Kkxdev\AppleIap\Exceptions\ApiException;
use Kkxdev\AppleIap\Exceptions\NetworkException;
use Kkxdev\AppleIap\Exceptions\ReceiptValidationException;
use Kkxdev\AppleIap\Support\CircuitBreaker;
use Kkxdev\AppleIap\Support\EnvironmentResolver;
use Psr\Log\LoggerInterface;

/**
 * Validates App Store receipts using the legacy verifyReceipt endpoint.
 *
 * @deprecated Apple has deprecated the verifyReceipt endpoint. Use AppStoreServerApi for new integrations.
 * @see https://developer.apple.com/documentation/appstorereceipts/verifyreceipt
 */
class LegacyReceiptValidator implements ReceiptValidatorInterface
{
    public function __construct(
        private HttpFactory $http,
        private EnvironmentResolver $environmentResolver,
        private array $config,
        private ?LoggerInterface $logger = null,
        private ?CircuitBreaker $circuitBreaker = null,
    ) {
    }

    public function validate(ValidateReceiptRequest $request): ValidateReceiptResponse
    {
        $sharedSecret = $request->sharedSecret ?? $this->config['credentials']['shared_secret'] ?? null;

        $payload = $request->toArray();
        if ($sharedSecret && !isset($payload['password'])) {
            $payload['password'] = $sharedSecret;
        }

        $environment = $this->environmentResolver->getEnvironment();
        $url         = $this->getUrl($environment);

        $response = $this->post($url, $payload);
        $status   = (int) ($response['status'] ?? -1);

        // Status 21007: receipt is from sandbox but sent to production — retry against sandbox
        if ($status === 21007 && $environment === 'production') {
            $this->log('info', 'Receipt is from sandbox; retrying against sandbox endpoint.');
            $sandboxUrl = $this->getUrl('sandbox');
            $response   = $this->post($sandboxUrl, $payload);
            $status     = (int) ($response['status'] ?? -1);
            $environment = 'sandbox';
        }

        // Status 21008: receipt is from production but sent to sandbox — retry against production
        if ($status === 21008 && $environment === 'sandbox') {
            $this->log('info', 'Receipt is from production; retrying against production endpoint.');
            $productionUrl = $this->getUrl('production');
            $response      = $this->post($productionUrl, $payload);
            $status        = (int) ($response['status'] ?? -1);
            $environment   = 'production';
        }

        if ($status !== 0 && $status !== 21006) {
            throw ReceiptValidationException::fromStatus($status);
        }

        $appleEnvironment = ucfirst($environment);

        return ValidateReceiptResponse::fromArray($response, $appleEnvironment);
    }

    private function post(string $url, array $payload): array
    {
        $this->log('debug', "POST {$url}", ['payload_size' => strlen(json_encode($payload))]);

        $execute = function () use ($url, $payload): array {
            try {
                $response = $this->http
                    ->timeout($this->config['http']['timeout'] ?? 30)
                    ->withOptions(['connect_timeout' => $this->config['http']['connect_timeout'] ?? 10])
                    ->retry(
                        $this->config['http']['retry']['times'] ?? 3,
                        $this->config['http']['retry']['sleep'] ?? 100,
                    )
                    ->post($url, $payload);
            } catch (\Throwable $e) {
                throw new NetworkException("Apple receipt validation request failed: " . $e->getMessage(), 0, $e);
            }

            if ($response->serverError()) {
                throw new NetworkException(
                    "Apple receipt validation endpoint returned HTTP {$response->status()}."
                );
            }

            if ($response->failed()) {
                throw new NetworkException(
                    "Apple receipt validation endpoint returned HTTP {$response->status()}."
                );
            }

            return $response->json() ?? [];
        };

        if ($this->circuitBreaker !== null) {
            return $this->circuitBreaker->call($execute);
        }

        return $execute();
    }

    private function getUrl(string $environment): string
    {
        return $this->config['urls']['receipt_validation'][$environment]
            ?? throw new \InvalidArgumentException("No receipt validation URL for environment: {$environment}");
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (!($this->config['logging']['enabled'] ?? false)) {
            return;
        }

        if ($level === 'debug' && !($this->config['logging']['debug'] ?? false)) {
            return;
        }

        $this->logger?->{$level}("[AppleIap] {$message}", $context);
    }
}
