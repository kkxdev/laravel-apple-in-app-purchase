<?php

namespace Kkxdev\AppleIap\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kkxdev\AppleIap\Events\ReceiptValidated;
use Kkxdev\AppleIap\Exceptions\ReceiptValidationException;
use Kkxdev\AppleIap\Facades\AppleIap;
use Kkxdev\AppleIap\Tests\TestCase;

class ReceiptValidationTest extends TestCase
{
    public function test_validates_receipt_and_returns_response(): void
    {
        Event::fake();

        Http::fake([
            '*/verifyReceipt' => Http::response($this->validReceiptResponse(), 200),
        ]);

        $response = AppleIap::validateReceipt(base64_encode('fake-receipt-data'));

        $this->assertTrue($response->isValid());
        $this->assertCount(1, $response->latestReceiptInfo);
        $this->assertSame('Sandbox', $response->environment);

        Event::assertDispatched(ReceiptValidated::class);
    }

    public function test_retries_against_sandbox_on_status_21007(): void
    {
        Event::fake();

        Http::fake([
            'buy.itunes.apple.com/*' => Http::response(['status' => 21007], 200),
            'sandbox.itunes.apple.com/*' => Http::response($this->validReceiptResponse(), 200),
        ]);

        $response = AppleIap::validateReceipt(base64_encode('fake-receipt-data'));

        $this->assertTrue($response->isValid());
        $this->assertSame('Sandbox', $response->environment);
    }

    public function test_throws_on_invalid_receipt_status(): void
    {
        Http::fake([
            '*/verifyReceipt' => Http::response(['status' => 21003], 200),
        ]);

        $this->expectException(ReceiptValidationException::class);
        $this->expectExceptionMessage('could not be authenticated');

        AppleIap::validateReceipt(base64_encode('bad-receipt'));
    }

    public function test_latest_purchase_for_product_id(): void
    {
        Event::fake();

        Http::fake([
            '*/verifyReceipt' => Http::response($this->validReceiptResponse(), 200),
        ]);

        $response = AppleIap::validateReceipt(base64_encode('fake-receipt-data'));
        $purchase = $response->latestPurchaseFor('com.example.app.premium');

        $this->assertNotNull($purchase);
        $this->assertSame('com.example.app.premium', $purchase->productId);
    }

    private function validReceiptResponse(): array
    {
        return [
            'status'      => 0,
            'environment' => 'Sandbox',
            'receipt'     => [
                'bundle_id' => 'com.example.app',
                'in_app'    => [],
            ],
            'latest_receipt_info' => [
                [
                    'product_id'              => 'com.example.app.premium',
                    'transaction_id'          => 'tx-001',
                    'original_transaction_id' => 'orig-001',
                    'purchase_date_ms'        => (string)(time() * 1000),
                    'original_purchase_date_ms' => (string)(time() * 1000),
                    'expires_date_ms'         => (string)((time() + 2592000) * 1000),
                    'quantity'                => '1',
                    'is_trial_period'         => 'false',
                    'is_in_intro_offer_period' => 'false',
                ],
            ],
        ];
    }
}
