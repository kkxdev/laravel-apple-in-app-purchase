# Backend Flow: Handling a Purchase Callback from the iOS App

## What the App Sends

After a successful StoreKit purchase, the iOS app sends this payload to your backend:

```json
{
    "transactionId":      "2000001141887062",
    "transactionDate":    1774440277000,
    "productId":          "AU365",
    "transactionReceipt": "MIIUUgYJKoZIhvcNAQcCoIIUQzCC..."
}
```

| Field | Source (iOS) | Notes |
|---|---|---|
| `transactionId` | `SKPaymentTransaction.transactionIdentifier` | Unique for this transaction |
| `transactionDate` | `SKPaymentTransaction.transactionDate` | Milliseconds since epoch |
| `productId` | `SKPayment.productIdentifier` | The product the user bought |
| `transactionReceipt` | `Bundle.main.appStoreReceiptURL` (base64) | The PKCS7 receipt blob — **this is what Apple validates** |

> The receipt blob (`transactionReceipt`) is what gets sent to Apple for verification. The other fields are client-reported and must never be trusted on their own — they are only used after Apple's validation confirms them.

---

## Overview: What the Backend Must Do

```
App sends receipt
        │
        ▼
1. Authenticate the request (who is this user?)
        │
        ▼
2. Validate the receipt with Apple
        │
        ├── Apple says INVALID → reject, log, return 422
        │
        └── Apple says VALID
                │
                ▼
        3. Find the purchased product in the response
                │
                ▼
        4. Cross-check client-reported fields vs Apple's response
                │
                ▼
        5. Idempotency check (already processed this transaction?)
                │
                ├── Already processed → return 200 (no double-grant)
                │
                └── New transaction
                        │
                        ▼
                6. Determine product type & grant entitlement
                        │
                        ▼
                7. Persist subscription record
                        │
                        ▼
                8. Return success to the app
```

---

## Step-by-Step Implementation

### Step 1 — The API Endpoint

Create a route that receives the purchase payload. The user must be authenticated:

```php
// routes/api.php
use App\Http\Controllers\PurchaseController;

Route::middleware('auth:sanctum')->post('/purchases/apple', [PurchaseController::class, 'store']);
```

### Step 2 — Validate the Request Shape

```php
// app/Http/Requests/ApplePurchaseRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplePurchaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'transactionId'      => ['required', 'string'],
            'transactionDate'    => ['required', 'integer'],
            'productId'          => ['required', 'string'],
            'transactionReceipt' => ['required', 'string'],
        ];
    }
}
```

### Step 3 — The Controller

```php
// app/Http/Controllers/PurchaseController.php
namespace App\Http\Controllers;

use App\Http\Requests\ApplePurchaseRequest;
use App\Services\ApplePurchaseService;
use Illuminate\Http\JsonResponse;

class PurchaseController extends Controller
{
    public function __construct(
        private ApplePurchaseService $purchaseService
    ) {}

    public function store(ApplePurchaseRequest $request): JsonResponse
    {
        $result = $this->purchaseService->process(
            user:               $request->user(),
            transactionId:      $request->input('transactionId'),
            transactionDateMs:  $request->integer('transactionDate'),
            productId:          $request->input('productId'),
            receiptData:        $request->input('transactionReceipt'),
        );

        return response()->json($result);
    }
}
```

### Step 4 — The Purchase Service

This is the core of the backend logic:

```php
// app/Services/ApplePurchaseService.php
namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kkxdev\AppleIap\Exceptions\AppleIapException;
use Kkxdev\AppleIap\Exceptions\CircuitBreakerOpenException;
use Kkxdev\AppleIap\Facades\AppleIap;
use Kkxdev\AppleIap\DTO\Receipt\InAppPurchase;

class ApplePurchaseService
{
    /**
     * Known product IDs mapped to their subscription durations (days).
     * Adjust to match your App Store Connect product definitions.
     */
    private const PRODUCT_DURATIONS = [
        'AU365' => 365,
        'AU30'  => 30,
        'AU7'   => 7,
    ];

    public function process(
        User   $user,
        string $transactionId,
        int    $transactionDateMs,
        string $productId,
        string $receiptData,
    ): array {
        // ── 1. Validate the receipt with Apple ──────────────────────────────
        try {
            $receiptResponse = AppleIap::validateReceipt($receiptData);
        } catch (CircuitBreakerOpenException $e) {
            Log::warning('Apple IAP: circuit breaker open, cannot validate receipt', [
                'user_id'        => $user->id,
                'transaction_id' => $transactionId,
            ]);
            abort(503, 'Apple receipt validation is temporarily unavailable. Please try again shortly.');
        } catch (AppleIapException $e) {
            Log::error('Apple IAP: receipt validation failed', [
                'user_id'        => $user->id,
                'transaction_id' => $transactionId,
                'error'          => $e->getMessage(),
            ]);
            abort(422, 'Receipt validation failed: ' . $e->getMessage());
        }

        if (! $receiptResponse->isValid()) {
            Log::warning('Apple IAP: receipt returned non-zero status', [
                'user_id' => $user->id,
                'status'  => $receiptResponse->status,
            ]);
            abort(422, 'Invalid receipt.');
        }

        // ── 2. Find the specific transaction in Apple's response ─────────────
        // Apple returns ALL transactions for this app in latest_receipt_info.
        // We look for the one matching the transactionId the app reported.
        $purchase = $this->findTransaction($receiptResponse->latestReceiptInfo, $transactionId);

        if ($purchase === null) {
            // Fall back to the in_app array (older receipts)
            $purchase = $this->findTransaction($receiptResponse->inApp, $transactionId);
        }

        if ($purchase === null) {
            Log::warning('Apple IAP: transaction not found in receipt', [
                'user_id'        => $user->id,
                'transaction_id' => $transactionId,
            ]);
            abort(422, 'Transaction not found in receipt.');
        }

        // ── 3. Cross-check client-reported fields vs Apple's response ────────
        // Never trust what the app says about productId — verify it matches.
        if ($purchase->productId !== $productId) {
            Log::warning('Apple IAP: product ID mismatch', [
                'user_id'           => $user->id,
                'client_product_id' => $productId,
                'apple_product_id'  => $purchase->productId,
            ]);
            abort(422, 'Product ID mismatch.');
        }

        // Verify the purchase date is within a reasonable window of what the app reported.
        // This catches replayed old receipts being submitted as new purchases.
        $applePurchaseDateMs  = (int) $purchase->purchaseDateMs;
        $clientTransactionMs  = $transactionDateMs;
        $diffSeconds          = abs($applePurchaseDateMs - $clientTransactionMs) / 1000;

        if ($diffSeconds > 300) { // 5-minute tolerance
            Log::warning('Apple IAP: transaction date mismatch', [
                'user_id'         => $user->id,
                'client_date_ms'  => $clientTransactionMs,
                'apple_date_ms'   => $applePurchaseDateMs,
                'diff_seconds'    => $diffSeconds,
            ]);
            // Do not hard-reject — clocks can drift — but log it for review.
            // Uncomment to enforce strictly: abort(422, 'Transaction date mismatch.');
        }

        // ── 4. Idempotency — avoid double-granting ───────────────────────────
        $existing = Subscription::where('apple_transaction_id', $purchase->transactionId)->first();

        if ($existing !== null) {
            Log::info('Apple IAP: transaction already processed', [
                'user_id'        => $user->id,
                'transaction_id' => $purchase->transactionId,
            ]);
            // Return success — the app may be retrying after a network failure.
            return $this->buildResponse($existing, alreadyExisted: true);
        }

        // ── 5. Validate the product is one we know about ─────────────────────
        if (! array_key_exists($purchase->productId, self::PRODUCT_DURATIONS)) {
            Log::error('Apple IAP: unknown product ID received', [
                'user_id'    => $user->id,
                'product_id' => $purchase->productId,
            ]);
            abort(422, 'Unknown product.');
        }

        // ── 6. Persist and grant ─────────────────────────────────────────────
        $subscription = DB::transaction(function () use ($user, $purchase, $receiptResponse) {
            return $this->grantSubscription($user, $purchase, $receiptResponse->environment);
        });

        Log::info('Apple IAP: subscription granted', [
            'user_id'        => $user->id,
            'product_id'     => $subscription->product_id,
            'expires_at'     => $subscription->expires_at,
            'environment'    => $subscription->environment,
        ]);

        return $this->buildResponse($subscription, alreadyExisted: false);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * @param  InAppPurchase[]  $purchases
     */
    private function findTransaction(array $purchases, string $transactionId): ?InAppPurchase
    {
        foreach ($purchases as $purchase) {
            if ($purchase->transactionId === $transactionId) {
                return $purchase;
            }
        }
        return null;
    }

    private function grantSubscription(User $user, InAppPurchase $purchase, string $environment): Subscription
    {
        $durationDays = self::PRODUCT_DURATIONS[$purchase->productId];

        // For subscriptions Apple provides an explicit expiry date.
        // For consumables / non-subscriptions use the duration map.
        if ($purchase->expiresDateMs !== null) {
            $expiresAt = \Carbon\Carbon::createFromTimestampMs((int) $purchase->expiresDateMs);
        } else {
            $expiresAt = \Carbon\Carbon::now()->addDays($durationDays);
        }

        return Subscription::updateOrCreate(
            // Natural key: one active subscription per user per product
            [
                'user_id'    => $user->id,
                'product_id' => $purchase->productId,
            ],
            [
                'apple_transaction_id'          => $purchase->transactionId,
                'apple_original_transaction_id' => $purchase->originalTransactionId,
                'expires_at'                    => $expiresAt,
                'is_trial'                      => $purchase->isTrialPeriod,
                'environment'                   => $environment,
                'status'                        => 'active',
                'receipt_validated_at'          => now(),
            ]
        );
    }

    private function buildResponse(Subscription $subscription, bool $alreadyExisted): array
    {
        return [
            'status'          => 'success',
            'already_existed' => $alreadyExisted,
            'subscription'    => [
                'product_id'  => $subscription->product_id,
                'expires_at'  => $subscription->expires_at->toIso8601String(),
                'is_active'   => $subscription->expires_at->isFuture(),
                'environment' => $subscription->environment,
            ],
        ];
    }
}
```

### Step 5 — The Subscription Model and Migration

```php
// database/migrations/xxxx_create_subscriptions_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Product
            $table->string('product_id');                    // "AU365"
            $table->string('status')->default('active');     // active | expired | cancelled | revoked

            // Apple identifiers
            $table->string('apple_transaction_id')->unique();          // the specific transaction
            $table->string('apple_original_transaction_id')->index();  // stable across renewals

            // Dates
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('receipt_validated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Metadata
            $table->boolean('is_trial')->default(false);
            $table->string('environment')->default('Production'); // Production | Sandbox

            $table->timestamps();

            // One subscription record per user per product
            $table->unique(['user_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
```

```php
// app/Models/Subscription.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'status',
        'apple_transaction_id',
        'apple_original_transaction_id',
        'expires_at',
        'receipt_validated_at',
        'cancelled_at',
        'is_trial',
        'environment',
    ];

    protected $casts = [
        'expires_at'           => 'datetime',
        'receipt_validated_at' => 'datetime',
        'cancelled_at'         => 'datetime',
        'is_trial'             => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at?->isFuture();
    }
}
```

---

## Step 6 — Handle Renewals and Expiry via Server Notifications

Once the subscription is in your database, Apple sends **server notifications** whenever the state changes (renewal, expiry, billing failure, cancellation, refund). This is how you keep your database in sync without polling.

Register a webhook route:

```php
// routes/api.php
use App\Http\Controllers\AppleWebhookController;

Route::post('/webhooks/apple', [AppleWebhookController::class, 'handle'])
    ->middleware('apple-iap.verify-notification');
```

```php
// app/Http/Controllers/AppleWebhookController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kkxdev\AppleIap\Facades\AppleIap;

class AppleWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // processServerNotification() verifies the JWS, decodes it,
        // and fires the appropriate events (SubscriptionRenewed, SubscriptionExpired, etc.)
        AppleIap::processServerNotification($request->input('signedPayload'));

        // Always return 200 immediately — Apple will retry on non-200.
        return response()->noContent();
    }
}
```

Register your event listeners:

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    \Kkxdev\AppleIap\Events\SubscriptionRenewed::class    => [\App\Listeners\RenewSubscription::class],
    \Kkxdev\AppleIap\Events\SubscriptionExpired::class    => [\App\Listeners\ExpireSubscription::class],
    \Kkxdev\AppleIap\Events\SubscriptionCancelled::class  => [\App\Listeners\CancelSubscription::class],
    \Kkxdev\AppleIap\Events\SubscriptionRevoked::class    => [\App\Listeners\RevokeSubscription::class],
    \Kkxdev\AppleIap\Events\SubscriptionInGracePeriod::class => [\App\Listeners\HandleGracePeriod::class],
    \Kkxdev\AppleIap\Events\RefundIssued::class           => [\App\Listeners\HandleRefund::class],
];
```

Example listeners:

```php
// app/Listeners/RenewSubscription.php
namespace App\Listeners;

use App\Models\Subscription;
use Kkxdev\AppleIap\Events\SubscriptionRenewed;

class RenewSubscription
{
    public function handle(SubscriptionRenewed $event): void
    {
        $tx = $event->transaction;

        Subscription::where('apple_original_transaction_id', $tx->originalTransactionId)
            ->update([
                'apple_transaction_id' => $tx->transactionId,
                'status'               => 'active',
                'expires_at'           => $tx->expiresDateAsDateTime(),
                'is_trial'             => false,
            ]);
    }
}
```

```php
// app/Listeners/ExpireSubscription.php
namespace App\Listeners;

use App\Models\Subscription;
use Kkxdev\AppleIap\Events\SubscriptionExpired;

class ExpireSubscription
{
    public function handle(SubscriptionExpired $event): void
    {
        $tx = $event->transaction;

        Subscription::where('apple_original_transaction_id', $tx->originalTransactionId)
            ->update([
                'status'     => 'expired',
                'expires_at' => $tx->expiresDateAsDateTime(),
            ]);
    }
}
```

```php
// app/Listeners/HandleGracePeriod.php
namespace App\Listeners;

use App\Models\Subscription;
use Kkxdev\AppleIap\Events\SubscriptionInGracePeriod;

class HandleGracePeriod
{
    public function handle(SubscriptionInGracePeriod $event): void
    {
        $tx          = $event->transaction;
        $renewalInfo = $event->renewalInfo;

        // Keep the subscription active during grace period.
        // Extend expires_at to the grace period end so users retain access.
        Subscription::where('apple_original_transaction_id', $tx->originalTransactionId)
            ->update([
                'status'     => 'active', // still active — Apple is retrying payment
                'expires_at' => $renewalInfo?->gracePeriodExpiresDateAsDateTime() ?? $tx->expiresDateAsDateTime(),
            ]);
    }
}
```

```php
// app/Listeners/HandleRefund.php
namespace App\Listeners;

use App\Models\Subscription;
use Kkxdev\AppleIap\Events\RefundIssued;

class HandleRefund
{
    public function handle(RefundIssued $event): void
    {
        $tx = $event->transaction;

        Subscription::where('apple_original_transaction_id', $tx->originalTransactionId)
            ->update([
                'status'       => 'refunded',
                'cancelled_at' => now(),
            ]);

        // Revoke access immediately — user received a refund.
        // Fire your own internal event here if other parts of the system need to react.
    }
}
```

---

## Step 7 — Checking Subscription Status on API Requests

Add a helper to `User` or use a middleware:

```php
// app/Models/User.php

public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
{
    return $this->hasOne(\App\Models\Subscription::class)->latestOfMany('expires_at');
}

public function hasActiveSubscription(): bool
{
    return $this->subscription?->isActive() ?? false;
}
```

Protect routes:

```php
// app/Http/Middleware/RequireSubscription.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireSubscription
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->hasActiveSubscription()) {
            abort(403, 'An active subscription is required.');
        }

        return $next($request);
    }
}
```

```php
// routes/api.php
Route::middleware(['auth:sanctum', RequireSubscription::class])->group(function () {
    Route::get('/premium-content', PremiumController::class);
});
```

---

## Step 8 — Re-validating Stale Subscriptions (Optional but Recommended)

Apple's server notifications cover most state changes, but for extra reliability you can re-validate when a user opens the app and their subscription is about to expire:

```php
// app/Services/SubscriptionRefreshService.php
namespace App\Services;

use App\Models\User;
use Kkxdev\AppleIap\Facades\AppleIap;

class SubscriptionRefreshService
{
    /**
     * Refresh subscription status for a user by querying Apple directly.
     * Call this when: user opens the app, subscription is within 24h of expiry,
     * or you detect a gap between your records and Apple's state.
     */
    public function refresh(User $user): void
    {
        $subscription = $user->subscription;

        if ($subscription === null) {
            return;
        }

        $originalTransactionId = $subscription->apple_original_transaction_id;

        try {
            $statuses = AppleIap::getAllSubscriptionStatuses($originalTransactionId);
        } catch (\Throwable $e) {
            // Non-fatal — keep existing state if Apple is unreachable
            return;
        }

        foreach ($statuses->allSubscriptions() as $status) {
            $tx = $status->transactionInfo;
            if ($tx === null) {
                continue;
            }

            $subscription->update([
                'status'     => $status->isActive() ? 'active' : 'expired',
                'expires_at' => $tx->expiresDateAsDateTime(),
                'receipt_validated_at' => now(),
            ]);

            break; // only one subscription per user in this example
        }
    }
}
```

---

## Summary: Full Flow Diagram

```
iOS App                              Your Laravel Backend                   Apple
──────                               ─────────────────────                  ─────
  │                                          │                                 │
  │── POST /api/purchases/apple ────────────▶│                                 │
  │   {transactionId, productId,             │                                 │
  │    transactionDate, transactionReceipt}  │                                 │
  │                                          │── POST verifyReceipt ──────────▶│
  │                                          │◀── {status:0, latest_receipt_info, ...}
  │                                          │                                 │
  │                                          │ Verify:                         │
  │                                          │  ✓ status == 0                  │
  │                                          │  ✓ transactionId exists         │
  │                                          │  ✓ productId matches            │
  │                                          │  ✓ not duplicate                │
  │                                          │                                 │
  │                                          │── INSERT subscriptions ────────▶DB
  │                                          │                                 │
  │◀── {status:"success", expires_at} ───────│                                 │
  │                                          │                                 │
  │                                          │                                 │
  │                                          │◀── POST /webhooks/apple ────────│ (renewals,
  │                                          │   {signedPayload: "..."}        │  expiry, etc.)
  │                                          │                                 │
  │                                          │ Verify JWS signature            │
  │                                          │ Fire: SubscriptionRenewed, etc. │
  │                                          │── UPDATE subscriptions ────────▶DB
  │                                          │                                 │
  │                                          │── HTTP 200 ────────────────────▶│
```

---

## Security Checklist

| Check | Why |
|---|---|
| Always validate the receipt with Apple — never trust client-reported fields alone | App can send a fake `productId` or `transactionId` |
| Compare `productId` from the receipt against what the app claimed | Prevents product substitution attacks |
| Use `apple_transaction_id` as the idempotency key | Prevents double-granting on retries |
| Verify the receipt in the correct environment | Sandbox receipts must not grant production access |
| Use the `apple_original_transaction_id` (not `transactionId`) as the stable subscription identifier | `transactionId` changes on every renewal; `originalTransactionId` is stable for the subscription's lifetime |
| Respond 200 to webhook even if processing fails (then fix async) | Apple will retry on non-200, causing duplicate events |
| Verify webhook JWS signature via `apple-iap.verify-notification` middleware | Prevents forged notifications |
