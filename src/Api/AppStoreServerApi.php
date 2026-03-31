<?php

namespace Kkxdev\AppleIap\Api;

use Illuminate\Http\Client\Factory as HttpFactory;
use Kkxdev\AppleIap\Contracts\AppStoreServerApiInterface;
use Kkxdev\AppleIap\Contracts\JwsVerifierInterface;
use Kkxdev\AppleIap\DTO\ServerApi\AllSubscriptionStatusesResponse;
use Kkxdev\AppleIap\DTO\ServerApi\ExtendRenewalDateRequest;
use Kkxdev\AppleIap\DTO\ServerApi\ExtendRenewalDateResponse;
use Kkxdev\AppleIap\DTO\ServerApi\RefundLookupResponse;
use Kkxdev\AppleIap\DTO\ServerApi\SubscriptionGroupStatus;
use Kkxdev\AppleIap\DTO\ServerApi\SubscriptionStatus;
use Kkxdev\AppleIap\DTO\ServerApi\TransactionHistoryRequest;
use Kkxdev\AppleIap\DTO\ServerApi\TransactionHistoryResponse;
use Kkxdev\AppleIap\DTO\Transaction\JwsRenewalInfo;
use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;
use Kkxdev\AppleIap\Exceptions\ApiException;
use Kkxdev\AppleIap\Exceptions\NetworkException;
use Kkxdev\AppleIap\Support\AppStoreApiAuthenticator;
use Kkxdev\AppleIap\Support\CircuitBreaker;
use Kkxdev\AppleIap\Support\EnvironmentResolver;
use Psr\Log\LoggerInterface;

class AppStoreServerApi implements AppStoreServerApiInterface
{
    public function __construct(
        private HttpFactory $http,
        private AppStoreApiAuthenticator $authenticator,
        private JwsVerifierInterface $jwsVerifier,
        private EnvironmentResolver $environmentResolver,
        private array $config,
        private ?LoggerInterface $logger = null,
        private ?CircuitBreaker $circuitBreaker = null,
    ) {
    }

    public function getTransactionHistory(
        string $originalTransactionId,
        ?TransactionHistoryRequest $params = null
    ): TransactionHistoryResponse {
        $path = "/inApps/v1/history/{$originalTransactionId}";
        return $this->fetchTransactionHistory($path, $params);
    }

    public function getTransactionHistoryV2(
        string $originalTransactionId,
        ?TransactionHistoryRequest $params = null
    ): TransactionHistoryResponse {
        $path = "/inApps/v2/history/{$originalTransactionId}";
        return $this->fetchTransactionHistory($path, $params);
    }

    private function fetchTransactionHistory(
        string $path,
        ?TransactionHistoryRequest $params
    ): TransactionHistoryResponse {
        $query     = $params?->toQueryParams() ?? [];
        $response  = $this->get($path, $query);

        $transactions = array_map(
            fn (string $jws) => JwsTransaction::fromArray($this->jwsVerifier->verify($jws)),
            $response['signedTransactions'] ?? []
        );

        return TransactionHistoryResponse::fromArray($response, $transactions);
    }

    public function getAllSubscriptionStatuses(string $originalTransactionId): AllSubscriptionStatusesResponse
    {
        $response = $this->get("/inApps/v1/subscriptions/{$originalTransactionId}");

        $groups = [];
        foreach ($response['data'] ?? [] as $groupData) {
            $subscriptions = [];

            foreach ($groupData['lastTransactions'] ?? [] as $lastTx) {
                $transaction = null;
                $renewalInfo = null;

                if (!empty($lastTx['signedTransactionInfo'])) {
                    $transaction = JwsTransaction::fromArray(
                        $this->jwsVerifier->verify($lastTx['signedTransactionInfo'])
                    );
                }

                if (!empty($lastTx['signedRenewalInfo'])) {
                    $renewalInfo = JwsRenewalInfo::fromArray(
                        $this->jwsVerifier->verify($lastTx['signedRenewalInfo'])
                    );
                }

                $subscriptions[] = new SubscriptionStatus(
                    status:          $lastTx['status'] ?? 0,
                    renewalInfo:     $renewalInfo,
                    transactionInfo: $transaction,
                );
            }

            $groups[] = new SubscriptionGroupStatus(
                subscriptionGroupIdentifier: $groupData['subscriptionGroupIdentifier'] ?? '',
                subscriptions:              $subscriptions,
            );
        }

        return new AllSubscriptionStatusesResponse(
            appAppleId:  $response['appAppleId'] ?? '',
            bundleId:    $response['bundleId'] ?? '',
            environment: $response['environment'] ?? '',
            data:        $groups,
        );
    }

    public function lookUpOrderId(string $orderId): TransactionHistoryResponse
    {
        $response = $this->get("/inApps/v1/lookup/{$orderId}");

        $transactions = array_map(
            fn (string $jws) => JwsTransaction::fromArray($this->jwsVerifier->verify($jws)),
            $response['signedTransactions'] ?? []
        );

        return TransactionHistoryResponse::fromArray($response, $transactions);
    }

    public function getRefundHistory(string $originalTransactionId): RefundLookupResponse
    {
        $response = $this->get("/inApps/v1/refund/lookup/{$originalTransactionId}");

        $transactions = array_map(
            fn (string $jws) => JwsTransaction::fromArray($this->jwsVerifier->verify($jws)),
            $response['signedTransactions'] ?? []
        );

        return new RefundLookupResponse(
            hasMore:      (bool) ($response['hasMore'] ?? false),
            revision:     $response['revision'] ?? null,
            transactions: $transactions,
        );
    }

    public function extendSubscriptionRenewalDate(
        string $originalTransactionId,
        ExtendRenewalDateRequest $request
    ): ExtendRenewalDateResponse {
        $response = $this->put(
            "/inApps/v1/subscriptions/extend/{$originalTransactionId}",
            $request->toArray()
        );

        return ExtendRenewalDateResponse::fromArray($response);
    }

    public function sendTestNotification(): string
    {
        $response = $this->post('/inApps/v1/notifications/test', []);
        return $response['testNotificationToken'] ?? '';
    }

    public function getTestNotificationStatus(string $testNotificationToken): array
    {
        return $this->get("/inApps/v1/notifications/test/{$testNotificationToken}");
    }

    private function get(string $path, array $query = []): array
    {
        $url = $this->buildUrl($path);
        $this->log('debug', "GET {$url}", ['query' => $query]);

        return $this->execute(function () use ($url, $query): array {
            try {
                $response = $this->http
                    ->withToken($this->authenticator->getBearerToken())
                    ->timeout($this->config['http']['timeout'] ?? 30)
                    ->connectTimeout($this->config['http']['connect_timeout'] ?? 10)
                    ->retry(
                        $this->config['http']['retry']['times'] ?? 3,
                        $this->config['http']['retry']['sleep'] ?? 100,
                    )
                    ->get($url, $query);
            } catch (\Throwable $e) {
                throw new NetworkException("App Store Server API request failed: " . $e->getMessage(), 0, $e);
            }

            return $this->parseResponse($response);
        });
    }

    private function post(string $path, array $body): array
    {
        $url = $this->buildUrl($path);

        return $this->execute(function () use ($url, $body): array {
            try {
                $response = $this->http
                    ->withToken($this->authenticator->getBearerToken())
                    ->timeout($this->config['http']['timeout'] ?? 30)
                    ->connectTimeout($this->config['http']['connect_timeout'] ?? 10)
                    ->post($url, $body);
            } catch (\Throwable $e) {
                throw new NetworkException("App Store Server API request failed: " . $e->getMessage(), 0, $e);
            }

            return $this->parseResponse($response);
        });
    }

    private function put(string $path, array $body): array
    {
        $url = $this->buildUrl($path);

        return $this->execute(function () use ($url, $body): array {
            try {
                $response = $this->http
                    ->withToken($this->authenticator->getBearerToken())
                    ->timeout($this->config['http']['timeout'] ?? 30)
                    ->connectTimeout($this->config['http']['connect_timeout'] ?? 10)
                    ->put($url, $body);
            } catch (\Throwable $e) {
                throw new NetworkException("App Store Server API request failed: " . $e->getMessage(), 0, $e);
            }

            return $this->parseResponse($response);
        });
    }

    /**
     * Run a callable through the circuit breaker (if enabled), or directly.
     */
    private function execute(callable $callback): array
    {
        if ($this->circuitBreaker !== null) {
            return $this->circuitBreaker->call($callback);
        }

        return $callback();
    }

    private function parseResponse(\Illuminate\Http\Client\Response $response): array
    {
        if ($response->serverError()) {
            // 5xx — transient; should trip the circuit breaker
            $body     = $response->json() ?? [];
            $errorMsg = $body['errorMessage'] ?? null;
            throw new NetworkException(
                "App Store Server API returned HTTP {$response->status()}"
                . ($errorMsg ? ": {$errorMsg}" : '')
            );
        }

        if ($response->clientError()) {
            // 4xx — client error; should NOT trip the circuit breaker
            $body      = $response->json() ?? [];
            $errorCode = $body['errorCode'] ?? null;
            $errorMsg  = $body['errorMessage'] ?? null;

            throw new ApiException(
                "App Store Server API returned HTTP {$response->status()}"
                . ($errorMsg ? ": {$errorMsg}" : ''),
                $response->status(),
                $errorCode !== null ? (string) $errorCode : null,
                $errorMsg,
            );
        }

        return $response->json() ?? [];
    }

    private function buildUrl(string $path): string
    {
        $base = $this->environmentResolver->getServerApiBaseUrl($this->config['urls']);
        return rtrim($base, '/') . '/' . ltrim($path, '/');
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
