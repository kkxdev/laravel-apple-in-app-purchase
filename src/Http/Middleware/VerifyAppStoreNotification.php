<?php

namespace Kkxdev\AppleIap\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kkxdev\AppleIap\Contracts\NotificationVerifierInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that verifies the cryptographic signature of App Store Server Notifications.
 *
 * Rejects requests whose signedPayload cannot be verified against the Apple CA chain.
 * Apple expects a 200 response quickly; failed verification returns 400 immediately.
 *
 * Usage in routes:
 *   Route::post('/webhooks/apple', AppleIapWebhookController::class)
 *       ->middleware('apple-iap.verify-notification');
 */
class VerifyAppStoreNotification
{
    public function __construct(
        private NotificationVerifierInterface $verifier,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->input('signedPayload');

        if (empty($body)) {
            $this->log('warning', 'Received Apple notification with missing signedPayload.');
            return response()->json(['error' => 'Missing signedPayload'], 400);
        }

        if (!$this->verifier->isValid($body)) {
            $this->log('warning', 'Apple notification failed signature verification.', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Invalid notification signature'], 400);
        }

        return $next($request);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->{$level}("[AppleIap] {$message}", $context);
    }
}
