<?php

namespace Kkxdev\AppleIap\Tests;

use Kkxdev\AppleIap\AppleIapServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AppleIapServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'AppleIap' => \Kkxdev\AppleIap\Facades\AppleIap::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('apple-iap.environment', 'sandbox');
        $app['config']->set('apple-iap.credentials.bundle_id', 'com.example.testapp');
        $app['config']->set('apple-iap.credentials.shared_secret', 'test-shared-secret');
        $app['config']->set('apple-iap.credentials.key_id', 'TESTKEY123');
        $app['config']->set('apple-iap.credentials.issuer_id', 'test-issuer-id');
        $app['config']->set('apple-iap.credentials.private_key', $this->fakePrivateKey());
    }

    /**
     * Returns a fake EC P-256 private key for test token generation.
     * This is a test-only key and must never be used in production.
     */
    protected function fakePrivateKey(): string
    {
        return <<<PEM
-----BEGIN EC PRIVATE KEY-----
MHQCAQEEIOaRCLBivUHiUEPYGrMQVPBVDRWFDKBr3RTSPKvAGJ1GoAoGCCqGSM49
AwEHoWQDYgAEVFz+W8B7N4K9Fk1XMBk17U5LhY4SJvuvFb+KRLiXkJV6rJarJKlP
A5+Gm2Y8MgYc2nBvHJJ4Z8iFg42FJKBF
-----END EC PRIVATE KEY-----
PEM;
    }
}
