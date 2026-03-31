# Backend Flow: Handling a StoreKit 2 Purchase from the iOS App

## StoreKit 2 vs StoreKit 1 — What Changed

| | StoreKit 1 (Legacy) | StoreKit 2 (Current) |
|---|---|---|
| What the app sends | Base64 receipt blob (`transactionReceipt`) | JWS-signed transaction string (`jwsRepresentation`) |
| Verification method | Send receipt to Apple's `verifyReceipt` endpoint | Verify cryptographic signature locally — no Apple API call required |
| Trust model | Apple's server vouches for the receipt | Apple's signature on the JWS token is the proof |
| Transaction identifier | `transactionId` (changes on renewal) + `originalTransactionId` | Same — `transactionId` + `originalTransactionId` |
| Subscription status | Poll or use server notifications | App Store Server API + server notifications |
| Speed | Network round-trip to Apple | Sub-millisecond — pure crypto on your server |
| Reliability | Depends on Apple's `verifyReceipt` availability | Independent of Apple API availability for verification |

> **Bottom line:** With StoreKit 2, the app sends a self-contained cryptographically-signed token. Your server verifies it by walking the Apple certificate chain — no network call to Apple needed for the initial grant. You only call the App Store Server API when you need richer subscription data (renewal history, status polling, extending dates).

---

## What the iOS App Sends

After a successful `Product.purchase()` in StoreKit 2, the app gets a `Transaction` object. It sends the raw **JWS transaction string** to your backend.

### iOS-side code (for reference)

```swift
// Swift — what the iOS app does
switch await product.purchase() {
case .success(let verificationResult):
    guard case .verified(let transaction) = verificationResult else {
        // Transaction failed Apple's own local verification — do not send to backend
        return
    }

    // Send to your backend
    let payload: [String: Any] = [
        "jwsTransaction": transaction.jwsRepresentation,

        // Optional: send these for logging/debugging only.
        // Backend must NOT trust them — it extracts them from the JWS itself.
        "transactionId":   transaction.id,
        "originalTransactionId": transaction.originalID,
        "productId":       transaction.productID,
    ]

    await sendToBackend(payload)

    // IMPORTANT: only call this after the backend confirms success
    await transaction.finish()

case .userCancelled, .pending:
    break
}
```

### Sample Request Body

```json
{
    "jwsTransaction": "eyJhbGciOiJFUzI1NiIsIng1YyI6WyJNSUlFTURDQ0E3aWdBd0lCQWdJUUZrekxXVjBJT09FNGJiOEZHL0hhVFRBTkJna3Foa2lHOXcwQkFRc0ZBREIxTVVRd1FnWURWUVFERER0QmNIQnNaU0JYYjNKc1pIZHdaV0psWkNCRVpYWmxiRzl3WlhJZ1VtVnNZWGtnUTJWeWRHbG1hV05oZEdsdmJpQkJkWFJvYjNKcGRIa3hDekFKQmdOVkJBc01Ba2MxTVJNd0VRWURWUVFLREFwQmNIQnNaU0JKYm1NdU1Rc3dDUVlEVlFRR0V3SldVekFlRncweU5EQTNNVE14TmpVeE1ESmFGdzB5TmpBNE1qTXhOalV4TURKYSIsIk1JSUVNRENDQTV5Z0F3SUJBZ0lRQWsrekwrVzBJT09FNGJiOEZHL0hhVFRBTkJna3Foa2lHOXcwQkFRc0ZBREIxTVVRd1FnWURWUVFEREJ0QmNIQnNaU0JYYjNKc1pIZHdaV0psWkNCRVpYWmxiRzl3WlhJZ1VtVnNZWGtnUTJWeWRHbG1hV05oZEdsdmJpQkJkWFJvYjNKcGRIa3hDekFKQmdOVkJBc01Ba2MxTVJNd0VRWURWUVFLREFwQmNIQnNaU0JKYm1NdU1Rc3dDUVlEVlFRR0V3SldVakFlRncweU1EQXhNREV3TmpBd01EQmFGdzB5T0RBeE1ERXdOakF3TURCYU1HWXhSREJDQmdOVkJBTU1PMUpoYUc4Z1YyOXliR1FuZHlBaklHUmxkbVZzYjNCbGNpQkpaWEJsY25OcGIyNGdUR2xuYUhRZ1UyOXNkWFJwYjI0Z1VtVm5ZWGtnVTJsbmJtbHVaekVzTUNvR0ExVUVDd3dqUVhCd2JHVWdWMjl5YkdRbmR5QkVaWFpsYkc5d1pYSWdVbVZzWVhrZ1EyVnlkR2xtYVdOaGRHbHZiakVUTUJFR0ExVUVDZ3dLUVhCd2JHVWdTVzVqTGpFTE1Ba0dBMVVFQmhNQ1ZWTXdnZ0VpTUEwR0NTcUdTSWIzRFFFQkFRVUFBNElCRHdBd2dnRUtBb0lCQVFDdER6YWJ6emZhZ1hGYjF2RVUvQm5UOWRUd04wMWNSc0thS1VkUlliNnhQNWhaN0J3WHVxK3pDVmNGUk5jWGJWM1NNTU03TTZIVWlmUjJPVlpYTFRVL1RhbDRndEZhWWRaN3NDNlZWUEFIdjJEa0thUXpQVWV2ZG85ZEE1dWFPQW9oek44VWw0ZlVIV0hLS28zRVBsV3VmSjFpQUxBS0dEbTQ1aDJOODZRdzhaU1RZOXNUNlR5T0tmM1ZpSE96Rkpodk";,
    "transactionId":         "2000001141887062",
    "originalTransactionId": "2000001141887062",
    "productId":             "AU365"
}
```

> `jwsTransaction` is a standard JWS compact serialization: three base64url-encoded parts separated by dots — `HEADER.PAYLOAD.SIGNATURE`. The full real value is several kilobytes. The example above is truncated.

---

## The JWS Token — What's Inside

When you base64url-decode the middle part (the payload) of the `jwsTransaction`, you get:

### Decoded Header

```json
{
    "alg": "ES256",
    "x5c": [
        "MIIE...leaf certificate (DER, base64)...",
        "MIIE...Apple intermediate CA (DER, base64)...",
        "MIIC...Apple Root CA G3 (DER, base64)..."
    ]
}
```

The `x5c` array is the full certificate chain. Your backend walks this chain and verifies it terminates at the embedded Apple Root CA G3.

### Decoded Payload

```json
{
    "transactionId":               "2000001141887062",
    "originalTransactionId":       "2000001141887062",
    "bundleId":                    "com.example.yourapp",
    "productId":                   "AU365",
    "subscriptionGroupIdentifier": "21234567",
    "purchaseDate":                1742904277000,
    "originalPurchaseDate":        1742904277000,
    "expiresDate":                 1774440277000,
    "quantity":                    1,
    "type":                        "Auto-Renewable Subscription",
    "appAccountToken":             "7e3fb204-2a8a-4bd1-8a4f-1b3892cf1bda",
    "inAppOwnershipType":          "PURCHASED",
    "signedDate":                  1742904300000,
    "environment":                 "Production",
    "transactionReason":           "PURCHASE",
    "storefront":                  "AUS",
    "storefrontId":                "143460",
    "price":                       9990,
    "currency":                    "AUD"
}
```

### Key Fields Explained

| Field | Type | Description |
|---|---|---|
| `transactionId` | string | Unique ID for this specific transaction. Changes on every renewal. |
| `originalTransactionId` | string | Stable ID for the subscription's entire lifetime — **use this as your primary key**. |
| `bundleId` | string | Your app's bundle ID. Verify this matches your config. |
| `productId` | string | The product purchased. Verify this matches a known product. |
| `purchaseDate` | int (ms) | When this transaction was created. |
| `originalPurchaseDate` | int (ms) | When the subscription was first purchased. |
| `expiresDate` | int (ms) | When access expires. Present for subscriptions, absent for consumables. |
| `type` | string | `"Auto-Renewable Subscription"`, `"Consumable"`, `"Non-Consumable"`, `"Non-Renewing Subscription"` |
| `appAccountToken` | string (UUID) | An opaque UUID you set on the iOS side at purchase time to identify the user. Use this to associate the transaction with your user without relying on the transport payload. |
| `inAppOwnershipType` | string | `"PURCHASED"` or `"FAMILY_SHARED"` |
| `environment` | string | `"Production"` or `"Sandbox"` |
| `signedDate` | int (ms) | When Apple signed this token — used to prevent replay attacks. |
| `price` | int | Price in milliunits (9990 = $9.99 AUD). |
| `currency` | string | ISO 4217 currency code. |

---

## Backend Flow

```
App sends {jwsTransaction, ...}
        │
        ▼
1. Authenticate the request
        │
        ▼
2. Verify JWS signature (Apple Root CA chain + ES256) — LOCAL, no Apple API call
        │
        ├── Signature invalid → 401 / 422 — reject
        │
        └── Signature valid → decoded transaction payload
                │
                ▼
        3. Validate bundleId matches your app
                │
                ▼
        4. Check signedDate age — reject tokens older than 5 minutes
                │
                ▼
        5. Verify productId is a product you sell
                │
                ▼
        6. Idempotency — already processed this transactionId?
                │
                ├── Yes → return 200 (no double-grant)
                │
                └── No
                        │
                        ▼
                7. Match transaction to your user
                   (via appAccountToken or authenticated user)
                        │
                        ▼
                8. Persist subscription record
                        │
                        ▼
                9. Optionally call App Store Server API
                   for full subscription status
                        │
                        ▼
                10. Return success to app — app calls transaction.finish()
```

---

## Step-by-Step Implementation

### Step 1 — Route and Request Validation

```php
// routes/api.php
use App\Http\Controllers\StoreKit2PurchaseController;

Route::middleware('auth:sanctum')->post('/purchases/apple/sk2', [StoreKit2PurchaseController::class, 'store']);
```

```php
// app/Http/Requests/StoreKit2PurchaseRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreKit2PurchaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Required — the JWS token is the only thing that matters
            'jwsTransaction' => ['required', 'string'],

            // Optional — logged but never trusted for business logic
            'transactionId'         => ['sometimes', 'string'],
            'originalTransactionId' => ['sometimes', 'string'],
            'productId'             => ['sometimes', 'string'],
        ];
    }
}
```

### Step 2 — The Controller

```php
// app/Http/Controllers/StoreKit2PurchaseController.php
namespace App\Http\Controllers;

use App\Http\Requests\StoreKit2PurchaseRequest;
use App\Services\StoreKit2PurchaseService;
use Illuminate\Http\JsonResponse;

class StoreKit2PurchaseController extends Controller
{
    public function __construct(
        private StoreKit2PurchaseService $purchaseService
    ) {}

    public function store(StoreKit2PurchaseRequest $request): JsonResponse
    {
        $result = $this->purchaseService->process(
            user:           $request->user(),
            jwsTransaction: $request->input('jwsTransaction'),
        );

        return response()->json($result);
    }
}
```

### Step 3 — The Core Service

```php
// app/Services/StoreKit2PurchaseService.php
namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;
use Kkxdev\AppleIap\Exceptions\JwsVerificationException;
use Kkxdev\AppleIap\Facades\AppleIap;

class StoreKit2PurchaseService
{
    private const EXPECTED_BUNDLE_ID = 'com.example.yourapp'; // must match your config

    private const MAX_TOKEN_AGE_SECONDS = 300; // reject tokens older than 5 minutes

    private const KNOWN_PRODUCTS = [
        'AU365' => ['duration_days' => 365, 'type' => 'subscription'],
        'AU30'  => ['duration_days' => 30,  'type' => 'subscription'],
        'AU7'   => ['duration_days' => 7,   'type' => 'subscription'],
        'COINS_100' => ['type' => 'consumable'],
    ];

    public function process(User $user, string $jwsTransaction): array
    {
        // ── 1. Verify JWS signature against Apple's certificate chain ─────────
        // This is a local cryptographic check — no network call to Apple.
        // The package walks the x5c chain and verifies the ES256 signature.
        try {
            $transaction = AppleIap::decodeTransaction($jwsTransaction);
        } catch (JwsVerificationException $e) {
            Log::warning('StoreKit2: JWS verification failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            abort(422, 'Transaction verification failed: the token could not be authenticated.');
        }

        // ── 2. Verify bundleId matches your app ───────────────────────────────
        // Prevents tokens from a different app being submitted to your backend.
        if ($transaction->bundleId !== self::EXPECTED_BUNDLE_ID) {
            Log::warning('StoreKit2: wrong bundleId', [
                'user_id'           => $user->id,
                'received_bundle_id' => $transaction->bundleId,
                'expected_bundle_id' => self::EXPECTED_BUNDLE_ID,
            ]);
            abort(422, 'Bundle ID mismatch.');
        }

        // ── 3. Check token freshness — reject replayed old tokens ─────────────
        // signedDate is when Apple signed this JWS. A legitimate purchase
        // arrives within seconds. A token hours or days old is suspicious.
        $signedAtSeconds = (int) ($transaction->signedDate / 1000);
        $ageSeconds      = time() - $signedAtSeconds;

        if ($ageSeconds > self::MAX_TOKEN_AGE_SECONDS) {
            Log::warning('StoreKit2: stale token rejected', [
                'user_id'     => $user->id,
                'age_seconds' => $ageSeconds,
                'transaction_id' => $transaction->transactionId,
            ]);
            abort(422, 'Transaction token is too old. Please retry the purchase.');
        }

        // ── 4. Verify the product is one you sell ─────────────────────────────
        if (! array_key_exists($transaction->productId, self::KNOWN_PRODUCTS)) {
            Log::error('StoreKit2: unknown product', [
                'user_id'    => $user->id,
                'product_id' => $transaction->productId,
            ]);
            abort(422, 'Unknown product.');
        }

        // ── 5. Idempotency — never double-grant ───────────────────────────────
        $existing = Subscription::where('apple_transaction_id', $transaction->transactionId)->first();

        if ($existing !== null) {
            Log::info('StoreKit2: duplicate transaction, returning existing record', [
                'user_id'        => $user->id,
                'transaction_id' => $transaction->transactionId,
            ]);
            return $this->buildResponse($existing, alreadyExisted: true);
        }

        // ── 6. Resolve user from appAccountToken (if you set it on iOS side) ──
        // appAccountToken is a UUID you supply on the iOS side at purchase time
        // to bind the transaction to your user. It survives across renewals.
        // If you set it, you can look up users from server notifications without
        // needing the user to be authenticated at that moment.
        if ($transaction->appAccountToken !== null) {
            $tokenUser = User::where('apple_account_token', $transaction->appAccountToken)->first();

            if ($tokenUser !== null && $tokenUser->id !== $user->id) {
                // The token was associated with a different account.
                // This can happen if the same Apple ID is used on two of your accounts.
                // Policy decision: reject, merge, or log and allow. Here we log and allow.
                Log::warning('StoreKit2: appAccountToken user mismatch', [
                    'auth_user_id'  => $user->id,
                    'token_user_id' => $tokenUser->id,
                ]);
            }
        }

        // ── 7. Persist and grant ──────────────────────────────────────────────
        $subscription = DB::transaction(function () use ($user, $transaction) {
            return $this->grantEntitlement($user, $transaction);
        });

        Log::info('StoreKit2: entitlement granted', [
            'user_id'        => $user->id,
            'product_id'     => $transaction->productId,
            'expires_at'     => $subscription->expires_at,
            'environment'    => $transaction->environment,
        ]);

        return $this->buildResponse($subscription, alreadyExisted: false);
    }

    private function grantEntitlement(User $user, JwsTransaction $transaction): Subscription
    {
        // Determine expiry
        if ($transaction->expiresDate !== null) {
            // Apple provides the exact expiry for subscriptions
            $expiresAt = \Carbon\Carbon::createFromTimestampMs($transaction->expiresDate);
        } else {
            // Consumables / non-consumables do not expire
            $expiresAt = null;
        }

        return Subscription::updateOrCreate(
            [
                'user_id'    => $user->id,
                'product_id' => $transaction->productId,
            ],
            [
                'apple_transaction_id'          => $transaction->transactionId,
                'apple_original_transaction_id' => $transaction->originalTransactionId,
                'apple_account_token'           => $transaction->appAccountToken,
                'expires_at'                    => $expiresAt,
                'is_trial'                      => false,
                'environment'                   => $transaction->environment ?? 'Production',
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
                'expires_at'  => $subscription->expires_at?->toIso8601String(),
                'is_active'   => $subscription->isActive(),
                'environment' => $subscription->environment,
            ],
        ];
    }
}
```

---

## Step 4 — The Migration (Additions for StoreKit 2)

The table from the StoreKit 1 guide works. Add one column for `appAccountToken`:

```php
// Add to subscriptions migration (or a new migration)
$table->string('apple_account_token')->nullable()->index();
// Links transactions to users via the token set on the iOS side at purchase time.
// Indexed because server notifications use it to find users without authentication.
```

---

## Step 5 — Using `appAccountToken` to Link Users (Recommended)

`appAccountToken` is a UUID you generate on the iOS side and associate with your user before the purchase. Apple stores it and includes it in every transaction and server notification for that subscription — **for its entire lifetime including renewals**.

This is the correct way to find which user a server notification belongs to, because server notifications arrive without any authentication from your app.

### iOS side — set it before purchase

```swift
// Swift — set before calling product.purchase()
let purchaseOptions: Set<Product.PurchaseOption> = [
    .appAccountToken(UUID(uuidString: currentUser.appleAccountToken)!)
]
let result = await product.purchase(options: purchaseOptions)
```

`currentUser.appleAccountToken` is a UUID stored in your user table, generated once per user.

### Laravel side — generate and expose it

```php
// In User model or registration flow
if ($user->apple_account_token === null) {
    $user->update(['apple_account_token' => (string) \Illuminate\Support\Str::uuid()]);
}

// Expose via an API endpoint so the app can fetch it
// GET /api/user/apple-account-token
return response()->json(['token' => $request->user()->apple_account_token]);
```

### Why this matters for server notifications

Without `appAccountToken`, when Apple sends a renewal notification at 3 AM your webhook handler has no way to know which user it belongs to (there is no authenticated request). With it:

```php
// app/Listeners/RenewSubscription.php
public function handle(SubscriptionRenewed $event): void
{
    $tx = $event->transaction;

    // Find user by stable original transaction ID (most reliable)
    $subscription = Subscription::where(
        'apple_original_transaction_id',
        $tx->originalTransactionId
    )->first();

    // Alternatively, find by appAccountToken if originalTransactionId is not yet stored
    if ($subscription === null && $tx->appAccountToken !== null) {
        $user = User::where('apple_account_token', $tx->appAccountToken)->first();
        // create a subscription record for this user
    }

    $subscription?->update([
        'apple_transaction_id' => $tx->transactionId,
        'status'               => 'active',
        'expires_at'           => $tx->expiresDateAsDateTime(),
    ]);
}
```

---

## Step 6 — Server Notifications (Same Webhook, Richer Data)

The webhook setup is identical to StoreKit 1 — same URL, same `apple-iap.verify-notification` middleware, same events. With StoreKit 2, the notification payloads contain full decoded `JwsTransaction` and `JwsRenewalInfo` objects (not just receipt strings), so your listeners have richer data.

```php
// routes/api.php — unchanged from StoreKit 1 guide
Route::post('/webhooks/apple', [AppleWebhookController::class, 'handle'])
    ->middleware('apple-iap.verify-notification');
```

### Full Listener Example Using StoreKit 2 Data

```php
// app/Listeners/RenewSubscription.php
namespace App\Listeners;

use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Kkxdev\AppleIap\Events\SubscriptionRenewed;

class RenewSubscription
{
    public function handle(SubscriptionRenewed $event): void
    {
        $tx          = $event->transaction;   // JwsTransaction — fully decoded
        $renewalInfo = $event->renewalInfo;   // JwsRenewalInfo — renewal details

        if ($tx === null) {
            return;
        }

        $updated = Subscription::where('apple_original_transaction_id', $tx->originalTransactionId)
            ->update([
                'apple_transaction_id' => $tx->transactionId,
                'status'               => 'active',
                'expires_at'           => $tx->expiresDateAsDateTime(),
                'is_trial'             => false,
                'receipt_validated_at' => now(),
            ]);

        if ($updated === 0) {
            // Subscription not found by original transaction ID — could be a new device.
            // Try to find by appAccountToken and create the record.
            if ($tx->appAccountToken !== null) {
                $user = \App\Models\User::where('apple_account_token', $tx->appAccountToken)->first();

                if ($user !== null) {
                    Subscription::updateOrCreate(
                        [
                            'user_id'    => $user->id,
                            'product_id' => $tx->productId,
                        ],
                        [
                            'apple_transaction_id'          => $tx->transactionId,
                            'apple_original_transaction_id' => $tx->originalTransactionId,
                            'apple_account_token'           => $tx->appAccountToken,
                            'status'                        => 'active',
                            'expires_at'                    => $tx->expiresDateAsDateTime(),
                            'environment'                   => $tx->environment,
                            'receipt_validated_at'          => now(),
                        ]
                    );
                }
            } else {
                Log::warning('SubscriptionRenewed: could not find subscription to renew', [
                    'original_transaction_id' => $tx->originalTransactionId,
                    'product_id'              => $tx->productId,
                ]);
            }
        }

        // Log renewal details for analytics / support
        Log::info('Subscription renewed', [
            'original_transaction_id' => $tx->originalTransactionId,
            'product_id'              => $tx->productId,
            'expires_at'              => $tx->expiresDateAsDateTime()?->toIso8601String(),
            'will_auto_renew'         => $renewalInfo?->willAutoRenew(),
            'environment'             => $tx->environment,
        ]);
    }
}
```

---

## Step 7 — Querying Apple for Full Subscription State (Optional)

For the initial purchase the JWS token is sufficient — you do not need to call Apple. However, there are cases where you want the full picture:

- User contacts support ("my subscription is not working")
- Nightly reconciliation job
- Checking if a subscription is active when the user logs into a new device and has no receipt to send

```php
// app/Services/SubscriptionStatusService.php
namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Kkxdev\AppleIap\Facades\AppleIap;

class SubscriptionStatusService
{
    public function syncFromApple(User $user): void
    {
        $subscription = Subscription::where('user_id', $user->id)
            ->whereNotNull('apple_original_transaction_id')
            ->latest('expires_at')
            ->first();

        if ($subscription === null) {
            return;
        }

        try {
            $statuses = AppleIap::getAllSubscriptionStatuses(
                $subscription->apple_original_transaction_id
            );
        } catch (\Throwable $e) {
            // Non-fatal — keep existing state
            return;
        }

        foreach ($statuses->allSubscriptions() as $status) {
            $tx = $status->transactionInfo;
            if ($tx === null) {
                continue;
            }

            $newStatus = match (true) {
                $status->isActive()         => 'active',
                $status->isInGracePeriod()  => 'active',   // still active during grace period
                $status->isInBillingRetry() => 'past_due',
                $status->isRevoked()        => 'revoked',
                default                     => 'expired',
            };

            $subscription->update([
                'status'               => $newStatus,
                'expires_at'           => $tx->expiresDateAsDateTime(),
                'receipt_validated_at' => now(),
            ]);

            break;
        }
    }
}
```

---

## Step 8 — Restore Purchases (User Switches Devices or Reinstalls)

When a user reinstalls your app or gets a new device, StoreKit 2 can re-deliver their current entitlements. The app calls `Transaction.currentEntitlements` and sends each JWS token:

```json
{
    "jwsTransactions": [
        "eyJhbGciOiJFUzI1NiIsI...",
        "eyJhbGciOiJFUzI1NiIsI..."
    ]
}
```

```php
// routes/api.php
Route::middleware('auth:sanctum')->post('/purchases/apple/sk2/restore', [StoreKit2RestoreController::class, 'store']);
```

```php
// app/Http/Controllers/StoreKit2RestoreController.php
namespace App\Http\Controllers;

use App\Services\StoreKit2PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreKit2RestoreController extends Controller
{
    public function __construct(
        private StoreKit2PurchaseService $purchaseService
    ) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'jwsTransactions'   => ['required', 'array', 'min:1', 'max:20'],
            'jwsTransactions.*' => ['required', 'string'],
        ]);

        $results = [];
        foreach ($request->input('jwsTransactions') as $jws) {
            try {
                // Reuse the same service — idempotency handles already-processed transactions
                $results[] = $this->purchaseService->process(
                    user:           $request->user(),
                    jwsTransaction: $jws,
                );
            } catch (\Throwable $e) {
                // One failed token should not abort the whole restore
                $results[] = ['status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return response()->json(['restored' => $results]);
    }
}
```

> **Important:** On restore, tokens from `Transaction.currentEntitlements` will have `signedDate` from when Apple originally signed them — not right now. The 5-minute freshness check in the purchase service must be **relaxed or removed** for the restore endpoint, since these are legitimately old tokens.

```php
// app/Services/StoreKit2PurchaseService.php — add a parameter to skip freshness check
public function process(User $user, string $jwsTransaction, bool $skipFreshnessCheck = false): array
{
    // ...

    if (! $skipFreshnessCheck && $ageSeconds > self::MAX_TOKEN_AGE_SECONDS) {
        abort(422, 'Transaction token is too old.');
    }

    // ...
}
```

```php
// In the restore controller, pass skipFreshnessCheck: true
$results[] = $this->purchaseService->process(
    user:                $request->user(),
    jwsTransaction:      $jws,
    skipFreshnessCheck:  true,
);
```

---

## Complete Flow Diagram

```
iOS App                              Your Laravel Backend             Apple
──────                               ─────────────────────            ─────
  │                                          │                           │
  │  User taps "Subscribe"                   │                           │
  │                                          │                           │
  │── GET /api/user/apple-account-token ────▶│                           │
  │◀── {token: "uuid-abc-..."}               │                           │
  │                                          │                           │
  │  product.purchase(options: [             │                           │
  │    .appAccountToken(uuid-abc-...)        │                           │
  │  ])                                      │                           │
  │                                          │                           │
  │── POST /api/purchases/apple/sk2 ────────▶│                           │
  │   {jwsTransaction: "eyJ..."}             │                           │
  │                                          │  Verify JWS locally       │
  │                                          │  (no Apple API call)      │
  │                                          │  ✓ Apple CA chain valid   │
  │                                          │  ✓ ES256 signature valid  │
  │                                          │  ✓ bundleId matches       │
  │                                          │  ✓ token fresh            │
  │                                          │  ✓ product known          │
  │                                          │  ✓ not duplicate          │
  │                                          │                           │
  │                                          │── INSERT subscriptions ──▶DB
  │                                          │                           │
  │◀── {status:"success", expires_at} ───────│                           │
  │                                          │                           │
  │  transaction.finish() ✓                  │                           │
  │                                          │                           │
  │                              (later)     │                           │
  │                                          │◀── POST /webhooks/apple ──│ renewal /
  │                                          │   {signedPayload: "..."}  │ expiry / refund
  │                                          │                           │
  │                                          │  Verify JWS (local)       │
  │                                          │  Decode transaction +     │
  │                                          │  renewal info             │
  │                                          │  Fire SubscriptionRenewed │
  │                                          │── UPDATE subscriptions ──▶DB
  │                                          │── HTTP 200 ──────────────▶│
```

---

## Key Differences from StoreKit 1

| | StoreKit 1 | StoreKit 2 |
|---|---|---|
| App sends | `transactionReceipt` (blob) | `jwsTransaction` (JWS token) |
| Verification | Network call to `verifyReceipt` | Local ES256 + certificate chain check |
| Token freshness | Not applicable | Check `signedDate` age to block replays |
| User binding | Only via authenticated request | `appAccountToken` (survives device change) |
| Renewal flow | Server notifications only | Server notifications + `Transaction.currentEntitlements` |
| Restore purchases | App sends receipt again | App sends `jwsTransactions[]` from `currentEntitlements` |
| Latency | ~200–500ms (Apple network round trip) | ~1ms (local crypto) |
| Offline resilience | Fails if Apple unreachable | Verification always works; only status polling needs Apple |

---

## Security Checklist

| Check | Why |
|---|---|
| Verify JWS signature (the package does this) | Proves Apple signed the token — cannot be forged |
| Check `bundleId` in the decoded payload | Prevents tokens from other apps being submitted |
| Check `signedDate` age on purchase (not on restore) | Prevents replay attacks with old valid tokens |
| Never trust `productId` from the request body — read it from the JWS payload | App request body can be tampered with |
| Use `originalTransactionId` as the subscription primary key | `transactionId` changes on every renewal |
| Store `appAccountToken` on both user and subscription | Enables webhook → user lookup without authentication |
| Do not call `transaction.finish()` on iOS until the backend confirms success | Ensures the purchase is never lost if the backend is down |
| Return 200 from the webhook even if processing fails (handle errors asynchronously) | Apple retries on non-200, causing duplicate events |
