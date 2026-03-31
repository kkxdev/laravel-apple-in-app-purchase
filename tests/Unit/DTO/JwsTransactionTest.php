<?php

namespace Kkxdev\AppleIap\Tests\Unit\DTO;

use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;
use Kkxdev\AppleIap\Tests\TestCase;

class JwsTransactionTest extends TestCase
{
    public function test_from_array_maps_all_fields(): void
    {
        $now = time() * 1000;
        $future = ($now) + 86400000; // +1 day in ms

        $data = [
            'transactionId'               => 'tx-001',
            'originalTransactionId'       => 'orig-tx-001',
            'bundleId'                    => 'com.example.app',
            'productId'                   => 'premium_monthly',
            'subscriptionGroupIdentifier' => 'group-1',
            'purchaseDate'                => $now,
            'originalPurchaseDate'        => $now,
            'expiresDate'                 => $future,
            'quantity'                    => 1,
            'type'                        => 'Auto-Renewable Subscription',
            'appAccountToken'             => 'token-abc',
            'inAppOwnershipType'          => 'PURCHASED',
            'signedDate'                  => $now,
            'environment'                 => 'Sandbox',
            'transactionReason'           => 'PURCHASE',
            'storefront'                  => 'USA',
            'storefrontId'                => '143441',
            'price'                       => 999,
            'currency'                    => 'USD',
            'isUpgraded'                  => false,
        ];

        $tx = JwsTransaction::fromArray($data);

        $this->assertSame('tx-001', $tx->transactionId);
        $this->assertSame('orig-tx-001', $tx->originalTransactionId);
        $this->assertSame('com.example.app', $tx->bundleId);
        $this->assertSame('premium_monthly', $tx->productId);
        $this->assertSame('Auto-Renewable Subscription', $tx->type);
        $this->assertSame('Sandbox', $tx->environment);
        $this->assertSame(999, $tx->price);
        $this->assertSame('USD', $tx->currency);
        $this->assertFalse($tx->isExpired());
        $this->assertTrue($tx->isSandbox());
    }

    public function test_is_expired_when_expires_date_in_past(): void
    {
        $past = (time() - 86400) * 1000; // yesterday

        $tx = JwsTransaction::fromArray([
            'transactionId'         => 'tx-001',
            'originalTransactionId' => 'orig-001',
            'bundleId'              => 'com.example.app',
            'productId'             => 'product',
            'purchaseDate'          => $past,
            'originalPurchaseDate'  => $past,
            'expiresDate'           => $past,
            'quantity'              => 1,
            'inAppOwnershipType'    => 'PURCHASED',
            'signedDate'            => $past,
            'type'                  => 'Auto-Renewable Subscription',
        ]);

        $this->assertTrue($tx->isExpired());
    }

    public function test_expires_date_as_datetime_returns_null_when_not_set(): void
    {
        $tx = JwsTransaction::fromArray([
            'transactionId'         => 'tx-001',
            'originalTransactionId' => 'orig-001',
            'bundleId'              => 'com.example.app',
            'productId'             => 'product',
            'purchaseDate'          => time() * 1000,
            'originalPurchaseDate'  => time() * 1000,
            'quantity'              => 1,
            'inAppOwnershipType'    => 'PURCHASED',
            'signedDate'            => time() * 1000,
            'type'                  => 'Consumable',
        ]);

        $this->assertNull($tx->expiresDateAsDateTime());
    }

    public function test_purchase_date_as_datetime(): void
    {
        $timestamp = 1700000000;
        $ms = $timestamp * 1000;

        $tx = JwsTransaction::fromArray([
            'transactionId'         => 'tx-001',
            'originalTransactionId' => 'orig-001',
            'bundleId'              => 'com.example.app',
            'productId'             => 'product',
            'purchaseDate'          => $ms,
            'originalPurchaseDate'  => $ms,
            'quantity'              => 1,
            'inAppOwnershipType'    => 'PURCHASED',
            'signedDate'            => $ms,
            'type'                  => 'Consumable',
        ]);

        $this->assertSame($timestamp, $tx->purchaseDateAsDateTime()->getTimestamp());
    }
}
