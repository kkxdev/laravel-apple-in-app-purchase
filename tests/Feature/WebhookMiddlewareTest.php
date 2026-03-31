<?php

namespace Kkxdev\AppleIap\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Kkxdev\AppleIap\Contracts\NotificationVerifierInterface;
use Kkxdev\AppleIap\DTO\Notification\NotificationData;
use Kkxdev\AppleIap\DTO\Notification\ServerNotification;
use Kkxdev\AppleIap\Exceptions\NotificationVerificationException;
use Kkxdev\AppleIap\Http\Middleware\VerifyAppStoreNotification;
use Kkxdev\AppleIap\Tests\TestCase;

class WebhookMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->post('/test-webhook', function () {
            return response()->json(['ok' => true]);
        })->middleware(VerifyAppStoreNotification::class);
    }

    public function test_valid_notification_passes_through(): void
    {
        $this->mockVerifier(valid: true);

        $response = $this->postJson('/test-webhook', ['signedPayload' => 'valid.jws.token']);

        $response->assertStatus(200)->assertJson(['ok' => true]);
    }

    public function test_invalid_signature_returns_400(): void
    {
        $this->mockVerifier(valid: false);

        $response = $this->postJson('/test-webhook', ['signedPayload' => 'invalid.jws.token']);

        $response->assertStatus(400);
    }

    public function test_missing_signed_payload_returns_400(): void
    {
        $response = $this->postJson('/test-webhook', []);

        $response->assertStatus(400)->assertJsonFragment(['error' => 'Missing signedPayload']);
    }

    private function mockVerifier(bool $valid): void
    {
        $mock = \Mockery::mock(NotificationVerifierInterface::class);
        $mock->shouldReceive('isValid')->andReturn($valid);

        $this->app->instance(NotificationVerifierInterface::class, $mock);
        $this->app->instance(VerifyAppStoreNotification::class, new VerifyAppStoreNotification($mock));
    }
}
