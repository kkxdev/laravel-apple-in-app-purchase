<?php

namespace Kkxdev\AppleIap;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Kkxdev\AppleIap\Api\AppStoreServerApi;
use Kkxdev\AppleIap\Api\LegacyReceiptValidator;
use Kkxdev\AppleIap\Console\Commands\VerifyReceiptCommand;
use Kkxdev\AppleIap\Contracts\AppStoreServerApiInterface;
use Kkxdev\AppleIap\Contracts\JwsVerifierInterface;
use Kkxdev\AppleIap\Contracts\NotificationVerifierInterface;
use Kkxdev\AppleIap\Contracts\ReceiptValidatorInterface;
use Kkxdev\AppleIap\Crypto\JwsVerifier;
use Kkxdev\AppleIap\Crypto\JwtNotificationVerifier;
use Kkxdev\AppleIap\Http\Middleware\VerifyAppStoreNotification;
use Kkxdev\AppleIap\Support\AppStoreApiAuthenticator;
use Kkxdev\AppleIap\Support\CircuitBreaker;
use Kkxdev\AppleIap\Support\EnvironmentResolver;
use Kkxdev\AppleIap\Support\NotificationTypeResolver;
use Kkxdev\AppleIap\Support\PromotionalOfferSignatureGenerator;

class AppleIapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/apple-iap.php', 'apple-iap');

        $this->app->singleton(EnvironmentResolver::class, function ($app) {
            return new EnvironmentResolver($app['config']['apple-iap']);
        });

        $this->app->singleton(AppStoreApiAuthenticator::class, function ($app) {
            $config      = $app['config']['apple-iap'];
            $cacheStore  = $config['cache']['store'] ?? null;
            $cache       = $cacheStore
                ? Cache::store($cacheStore)
                : $app->make(CacheRepository::class);

            return new AppStoreApiAuthenticator(
                $config['credentials'],
                $cache,
            );
        });

        $this->app->singleton(JwsVerifierInterface::class, function ($app) {
            return new JwsVerifier();
        });

        $this->app->singleton(NotificationVerifierInterface::class, function ($app) {
            return new JwtNotificationVerifier(
                $app->make(JwsVerifierInterface::class),
            );
        });

        $this->app->singleton(ReceiptValidatorInterface::class, function ($app) {
            $config = $app['config']['apple-iap'];
            return new LegacyReceiptValidator(
                $app->make(HttpFactory::class),
                $app->make(EnvironmentResolver::class),
                $config,
                $this->resolveLogger($config),
                $this->resolveCircuitBreaker($config, 'receipt_validation'),
            );
        });

        $this->app->singleton(AppStoreServerApiInterface::class, function ($app) {
            $config = $app['config']['apple-iap'];
            return new AppStoreServerApi(
                $app->make(HttpFactory::class),
                $app->make(AppStoreApiAuthenticator::class),
                $app->make(JwsVerifierInterface::class),
                $app->make(EnvironmentResolver::class),
                $config,
                $this->resolveLogger($config),
                $this->resolveCircuitBreaker($config, 'server_api'),
            );
        });

        $this->app->singleton(NotificationTypeResolver::class, function ($app) {
            return new NotificationTypeResolver();
        });

        $this->app->singleton(PromotionalOfferSignatureGenerator::class, function ($app) {
            $config = $app['config']['apple-iap'];
            return new PromotionalOfferSignatureGenerator(
                bundleId:       $config['credentials']['bundle_id'] ?? '',
                keyIdentifier:  $this->resolvePromoKeyId($config),
                privateKey:     $this->resolvePromoPrivateKey($config),
            );
        });

        $this->app->singleton(AppleIapManager::class, function ($app) {
            return new AppleIapManager(
                $app->make(ReceiptValidatorInterface::class),
                $app->make(AppStoreServerApiInterface::class),
                $app->make(JwsVerifierInterface::class),
                $app->make(NotificationVerifierInterface::class),
                $app->make(NotificationTypeResolver::class),
                $app->make(PromotionalOfferSignatureGenerator::class),
                $app->make(Dispatcher::class),
                $app['config']['apple-iap'],
            );
        });

        $this->app->alias(AppleIapManager::class, 'apple-iap');

        $this->app->singleton(VerifyAppStoreNotification::class, function ($app) {
            return new VerifyAppStoreNotification(
                $app->make(NotificationVerifierInterface::class),
                $this->resolveLogger($app['config']['apple-iap']),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/apple-iap.php' => config_path('apple-iap.php'),
            ], 'apple-iap-config');

            $this->commands([
                VerifyReceiptCommand::class,
            ]);
        }

        $this->registerMiddleware();
    }

    private function registerMiddleware(): void
    {
        $router = $this->app['router'];

        if (method_exists($router, 'aliasMiddleware')) {
            $router->aliasMiddleware('apple-iap.verify-notification', VerifyAppStoreNotification::class);
        } else {
            $router->middleware('apple-iap.verify-notification', VerifyAppStoreNotification::class);
        }
    }

    private function resolveCircuitBreaker(array $config, string $service): ?CircuitBreaker
    {
        $cbConfig = $config['circuit_breaker'] ?? [];

        if (!($cbConfig['enabled'] ?? true)) {
            return null;
        }

        $cacheStore = $cbConfig['cache_store'] ?? null;
        $cache = $cacheStore
            ? Cache::store($cacheStore)
            : $this->app->make(CacheRepository::class);

        return new CircuitBreaker(
            service:          $service,
            cache:            $cache,
            failureThreshold: (int) ($cbConfig['failure_threshold'] ?? 5),
            recoveryTimeout:  (int) ($cbConfig['recovery_timeout'] ?? 60),
            successThreshold: (int) ($cbConfig['success_threshold'] ?? 2),
        );
    }

    private function resolvePromoKeyId(array $config): string
    {
        return $config['promotional_offers']['key_id']
            ?? $config['credentials']['key_id']
            ?? '';
    }

    private function resolvePromoPrivateKey(array $config): string
    {
        // Prefer the dedicated promotional-offer key; fall back to the main API key.
        $promoSection = $config['promotional_offers'] ?? [];

        $keyContents = $promoSection['private_key'] ?? null;

        if (!$keyContents) {
            $keyPath = $promoSection['private_key_path'] ?? null;
            if ($keyPath && file_exists($keyPath)) {
                $keyContents = file_get_contents($keyPath) ?: null;
            }
        }

        if (!$keyContents) {
            $keyContents = $config['credentials']['private_key'] ?? null;
        }

        if (!$keyContents) {
            $keyPath = $config['credentials']['private_key_path'] ?? null;
            if ($keyPath && file_exists($keyPath)) {
                $keyContents = file_get_contents($keyPath) ?: null;
            }
        }

        return $keyContents ?? '';
    }

    private function resolveLogger(array $config): ?\Psr\Log\LoggerInterface
    {
        if (!($config['logging']['enabled'] ?? false)) {
            return null;
        }

        $channel = $config['logging']['channel'] ?? null;

        return $channel ? Log::channel($channel) : Log::getFacadeRoot();
    }

    public function provides(): array
    {
        return [
            AppleIapManager::class,
            'apple-iap',
            ReceiptValidatorInterface::class,
            AppStoreServerApiInterface::class,
            JwsVerifierInterface::class,
            NotificationVerifierInterface::class,
            AppStoreApiAuthenticator::class,
            EnvironmentResolver::class,
            NotificationTypeResolver::class,
            PromotionalOfferSignatureGenerator::class,
        ];
    }
}
