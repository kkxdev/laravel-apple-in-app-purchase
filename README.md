# Laravel Apple In-App Purchase

A Laravel package for Apple In-App Purchase — receipt validation, App Store Server API (StoreKit 2), JWS transaction verification, and App Store Server Notifications v2.

Core IAP functionality lives in the package. Subscription state management (active/expired/grace period tracking, database records) is left to your application via Laravel events.

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.0` |
| Laravel | `^8.0 \| ^9.0 \| ^10.0 \| ^11.0 \| ^12.0` |

## Installation

Install via Composer:

```bash
composer require kkxdev/laravel-apple-iap
```

The service provider and `AppleIap` facade are auto-discovered via Laravel's package discovery.

Publish the config file:

```bash
php artisan vendor:publish --tag=apple-iap-config
```

This creates `config/apple-iap.php` in your application.

## Configuration

Add the following variables to your `.env` file:

```env
# Environment: "production" or "sandbox"
APPLE_IAP_ENVIRONMENT=production

# Your app's bundle identifier
APPLE_IAP_BUNDLE_ID=com.example.yourapp

# Shared secret for legacy receipt validation (from App Store Connect)
APPLE_IAP_SHARED_SECRET=your_shared_secret_here

# App Store Server API credentials (from App Store Connect > Keys)
APPLE_IAP_KEY_ID=ABCD123456
APPLE_IAP_ISSUER_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx

# Path to your .p8 private key file (downloaded from App Store Connect)
APPLE_IAP_PRIVATE_KEY_PATH=/path/to/AuthKey_ABCD123456.p8

# Optional: pass key contents directly instead of a file path
# APPLE_IAP_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n..."

# Promotional Offer Signatures — optional, falls back to APPLE_IAP_KEY_ID / APPLE_IAP_PRIVATE_KEY_PATH above
# Use these if you want a dedicated Subscription Key for promotional offer signing.
# APPLE_IAP_PROMO_KEY_ID=ZZZZ999999
# APPLE_IAP_PROMO_PRIVATE_KEY_PATH=/path/to/SubscriptionKey_ZZZZ999999.p8
```

### Webhook URL

Register your notification URL in **App Store Connect → Your App → App Information → App Store Server Notifications**:

```
https://your-app.com/webhooks/apple
```

The default path can be changed in config:

```env
APPLE_IAP_WEBHOOK_PATH=/webhooks/apple
```

### Circuit Breaker

The circuit breaker is enabled by default and wraps all HTTP calls to Apple's APIs. It opens after 5 consecutive network failures and probes recovery after 60 seconds:

```env
APPLE_IAP_CB_ENABLED=true
APPLE_IAP_CB_FAILURES=5    # open after N failures
APPLE_IAP_CB_RECOVERY=60   # seconds before half-open probe
APPLE_IAP_CB_SUCCESSES=2   # successes needed to close again
```

## Usage

### Facade

All functionality is available through the `AppleIap` facade:

```php
use Kkxdev\AppleIap\Facades\AppleIap;
```

---

### App Store Server Notifications v2 (Recommended)

Register a webhook route and process incoming notifications:

```php
// routes/api.php
use Illuminate\Http\Request;
use Kkxdev\AppleIap\Facades\AppleIap;

Route::post('/webhooks/apple', function (Request $request) {
    AppleIap::processServerNotification($request->input('signedPayload'));
    return response()->noContent();
})->middleware('apple-iap.verify-notification');
```

The `apple-iap.verify-notification` middleware verifies the cryptographic signature of every incoming notification before your controller runs. Requests with invalid signatures receive a `400` response automatically.

`processServerNotification()` fires:
1. `ServerNotificationReceived` — always (catch-all)
2. A specific typed event matching the notification type (e.g. `SubscriptionRenewed`)

#### Listening to Events

Register listeners in your `EventServiceProvider`:

```php
use Kkxdev\AppleIap\Events\SubscriptionPurchased;
use Kkxdev\AppleIap\Events\SubscriptionRenewed;
use Kkxdev\AppleIap\Events\SubscriptionExpired;
use Kkxdev\AppleIap\Events\SubscriptionCancelled;
use Kkxdev\AppleIap\Events\RefundIssued;

protected $listen = [
    SubscriptionPurchased::class  => [HandleSubscriptionPurchased::class],
    SubscriptionRenewed::class    => [HandleSubscriptionRenewed::class],
    SubscriptionExpired::class    => [HandleSubscriptionExpired::class],
    SubscriptionCancelled::class  => [HandleSubscriptionCancelled::class],
    RefundIssued::class           => [HandleRefundIssued::class],
];
```

#### Example Listener

```php
namespace App\Listeners;

use Kkxdev\AppleIap\Events\SubscriptionRenewed;

class HandleSubscriptionRenewed
{
    public function handle(SubscriptionRenewed $event): void
    {
        $tx         = $event->transaction;   // JwsTransaction
        $renewal    = $event->renewalInfo;   // JwsRenewalInfo|null
        $notification = $event->notification; // ServerNotification

        // Update your database — the package does not touch it
        \App\Models\Subscription::where(
            'apple_original_transaction_id',
            $tx->originalTransactionId
        )->update([
            'product_id'         => $tx->productId,
            'expires_at'         => $tx->expiresDateAsDateTime(),
            'auto_renews'        => $renewal?->willAutoRenew(),
            'environment'        => $tx->environment,
        ]);
    }
}
```

---

### All Available Events

| Event | Fired when |
|---|---|
| `ServerNotificationReceived` | Every successfully verified notification (catch-all) |
| `SubscriptionPurchased` | New auto-renewable subscription |
| `SubscriptionRenewed` | Subscription successfully renewed |
| `SubscriptionExpired` | Subscription expired (billing retry exhausted or product removed) |
| `SubscriptionCancelled` | User disabled auto-renew (`EXPIRED + VOLUNTARY`) |
| `SubscriptionRevoked` | Family-sharing access revoked |
| `SubscriptionInBillingRetry` | Payment failed; Apple retrying |
| `SubscriptionInGracePeriod` | Payment failed but grace period is active |
| `GracePeriodExpired` | Grace period ended without successful payment |
| `SubscriptionAutoRenewEnabled` | User re-enabled auto-renew |
| `SubscriptionAutoRenewDisabled` | User disabled auto-renew (will expire at period end) |
| `SubscriptionPlanChanged` | User downgraded or upgraded plan |
| `SubscriptionOfferRedeemed` | Promotional or offer code redeemed |
| `SubscriptionPriceIncrease` | Apple notified user of price increase |
| `SubscriptionExpiredPriceIncrease` | Subscription expired because user declined price increase |
| `ConsumablePurchased` | Consumable IAP purchased |
| `NonConsumablePurchased` | Non-consumable IAP purchased |
| `NonRenewingSubscriptionPurchased` | Non-renewing subscription purchased |
| `RefundIssued` | Refund granted by Apple |
| `RefundDeclined` | Refund request declined |
| `RefundReversed` | Previously granted refund reversed |
| `ConsumptionRequest` | Apple requesting consumption data |
| `RenewalExtension` | Subscription renewal date was extended |
| `OneTimeChargePurchased` | One-time charge (e.g. consumable via StoreKit 2) |
| `ReceiptValidated` | Legacy receipt successfully validated |
| `TransactionVerified` | JWS transaction decoded and verified |

Every event exposes the raw decoded DTOs — your listener has everything it needs without making additional API calls.

---

### App Store Server API (StoreKit 2)

#### Get Transaction History

```php
use Kkxdev\AppleIap\DTO\ServerApi\TransactionHistoryRequest;
use Kkxdev\AppleIap\Facades\AppleIap;

$history = AppleIap::getTransactionHistory($originalTransactionId);

foreach ($history->transactions as $tx) {
    echo $tx->productId;
    echo $tx->expiresDateAsDateTime()?->format('Y-m-d');
}

// With filters
$request = new TransactionHistoryRequest(
    productTypes: ['Auto-Renewable Subscription'],
    sort: 'DESCENDING',
);
$history = AppleIap::getTransactionHistory($originalTransactionId, $request);

// Paginate — hasMore indicates additional pages
while ($history->hasMore) {
    $next = new TransactionHistoryRequest(revision: $history->revision);
    $history = AppleIap::getTransactionHistory($originalTransactionId, $next);
}
```

#### Get Subscription Statuses

```php
$statuses = AppleIap::getAllSubscriptionStatuses($originalTransactionId);

if ($statuses->hasActiveSubscription()) {
    // At least one subscription is active
}

foreach ($statuses->data as $group) {
    foreach ($group->subscriptions as $sub) {
        echo match(true) {
            $sub->isActive()        => 'Active',
            $sub->isInGracePeriod() => 'Grace period',
            $sub->isInBillingRetry()=> 'Billing retry',
            $sub->isExpired()       => 'Expired',
            $sub->isRevoked()       => 'Revoked',
            default                 => 'Unknown',
        };
    }
}
```

#### Look Up by Order ID

```php
$result = AppleIap::lookUpOrderId($orderId);
```

#### Get Refund History

```php
$refunds = AppleIap::getRefundHistory($originalTransactionId);

foreach ($refunds->transactions as $tx) {
    echo "Refunded: {$tx->productId} on {$tx->purchaseDateAsDateTime()->format('Y-m-d')}";
}
```

#### Extend a Subscription Renewal Date

```php
use Kkxdev\AppleIap\DTO\ServerApi\ExtendRenewalDateRequest;

$request = new ExtendRenewalDateRequest(
    extendByDays:      30,
    extendReasonCode:  1,           // 1 = customer satisfaction issue
    requestIdentifier: 'unique-id-for-idempotency',
    productId:         'com.example.app.premium',
);

$result = AppleIap::extendSubscriptionRenewalDate($originalTransactionId, $request);

if ($result->success) {
    echo "New expiry: " . (new DateTime())->setTimestamp($result->effectiveDate / 1000)->format('Y-m-d');
}
```

#### Send a Test Notification

```php
$token = AppleIap::sendTestNotification();

// Check delivery status
$status = AppleIap::getTestNotificationStatus($token);
```

---

### Verifying JWS Transactions Directly

When your app sends a `StoreKit 2` transaction to your server, verify it directly:

```php
use Kkxdev\AppleIap\Facades\AppleIap;
use Kkxdev\AppleIap\Exceptions\JwsVerificationException;

try {
    $transaction = AppleIap::decodeTransaction($jwsTransactionFromApp);

    echo $transaction->transactionId;
    echo $transaction->originalTransactionId;
    echo $transaction->productId;
    echo $transaction->type; // "Auto-Renewable Subscription", "Consumable", etc.
    echo $transaction->environment; // "Production" or "Sandbox"

    if (!$transaction->isExpired()) {
        // Grant entitlement
    }
} catch (JwsVerificationException $e) {
    // Token did not pass Apple CA chain verification — reject it
}
```

Decode renewal info:

```php
$renewalInfo = AppleIap::decodeRenewalInfo($jwsRenewalInfoFromApp);

echo $renewalInfo->autoRenewStatus;      // 1 = will renew, 0 = won't
echo $renewalInfo->isInBillingRetryPeriod ? 'Retrying' : 'OK';
```

---

### Promotional Offer Signatures

Promotional offers let you give discounted (or free) subscription periods to existing or lapsed subscribers. Because generating the signature requires your private key, **it must always be done on a secure server** — never on the device.

#### How it works

1. Your iOS app determines the user is eligible for a promotional offer.
2. The app calls your backend to obtain a signed payload.
3. The backend calls `AppleIap::generatePromotionalOfferSignature()` and returns the result.
4. The iOS app passes the four values to StoreKit when initiating the purchase.
5. Apple verifies the signature; on success the promotional price applies.

#### Basic usage

```php
use Kkxdev\AppleIap\Facades\AppleIap;
use Kkxdev\AppleIap\Exceptions\AppleIapException;

$signature = AppleIap::generatePromotionalOfferSignature(
    productIdentifier:   'com.example.app.pro.monthly',
    offerIdentifier:     'monthly_winback_50_off',   // the code you set in App Store Connect
    applicationUsername: $user->apple_account_token ?? '',
);

// Return this to the iOS app:
return response()->json($signature->toArray());
```

The `toArray()` response contains exactly the four fields StoreKit requires:

```json
{
    "keyIdentifier": "ABCD1234EF",
    "nonce":         "3d4e5f6a-7b8c-4d9e-af01-234567890abc",
    "timestamp":     1742904277000,
    "signature":     "MEUCIQD3..."
}
```

> **Note:** A new `nonce` is generated automatically on every call. Each nonce is single-use — Apple rejects duplicate nonces. Signatures expire after 24 hours.

#### iOS integration (StoreKit 2)

```swift
// Fetch the signature from your server
let sig = try await api.fetchPromotionalOfferSignature(
    productId: "com.example.app.pro.monthly",
    offerId:   "monthly_winback_50_off"
)

let purchaseOptions: Set<Product.PurchaseOption> = [
    .promotionalOffer(
        offerID:   sig.offerIdentifier,
        keyID:     sig.keyIdentifier,
        nonce:     UUID(uuidString: sig.nonce)!,
        signature: Data(base64Encoded: sig.signature)!,
        timestamp: sig.timestamp
    ),
    .appAccountToken(UUID(uuidString: currentUser.appleAccountToken)!)
]

let result = try await product.purchase(options: purchaseOptions)
```

#### iOS integration (StoreKit 1 / SKPaymentDiscount)

```swift
let discount = SKPaymentDiscount(
    identifier:    "monthly_winback_50_off",
    keyIdentifier: sig.keyIdentifier,
    nonce:         UUID(uuidString: sig.nonce)!,
    signature:     sig.signature,
    timestamp:     NSNumber(value: sig.timestamp)
)

let payment = SKMutablePayment(product: skProduct)
payment.applicationUsername = currentUser.appleAccountToken
payment.paymentDiscount     = discount
SKPaymentQueue.default().add(payment)
```

#### Example controller

```php
use Illuminate\Http\Request;
use Kkxdev\AppleIap\Facades\AppleIap;

class PromotionalOfferController extends Controller
{
    public function generate(Request $request)
    {
        $request->validate([
            'product_id' => 'required|string',
            'offer_id'   => 'required|string',
        ]);

        // Verify the user is actually eligible before signing anything.
        $user = $request->user();

        abort_unless($this->isEligible($user, $request->product_id), 403, 'Not eligible for this offer.');

        $signature = AppleIap::generatePromotionalOfferSignature(
            productIdentifier:   $request->product_id,
            offerIdentifier:     $request->offer_id,
            applicationUsername: $user->apple_account_token ?? '',
        );

        return response()->json($signature->toArray());
    }

    private function isEligible($user, string $productId): bool
    {
        // Only offer to users who have previously subscribed.
        return $user->subscriptions()
            ->where('apple_product_id', $productId)
            ->whereNotNull('expired_at')
            ->exists();
    }
}
```

#### Using a dedicated Subscription Key

By default the package reuses the App Store Server API key (`APPLE_IAP_KEY_ID` / `APPLE_IAP_PRIVATE_KEY_PATH`). If you want a separate key downloaded from **App Store Connect → Users and Access → Keys → In-App Purchase**:

```env
APPLE_IAP_PROMO_KEY_ID=ZZZZ999999
APPLE_IAP_PROMO_PRIVATE_KEY_PATH=/path/to/SubscriptionKey_ZZZZ999999.p8
```

#### `applicationUsername` rules

| Scenario | Value to pass |
|---|---|
| You use `appAccountToken` UUIDs | Pass the user's UUID string (lowercase) |
| You don't use `appAccountToken` | Pass an empty string `""` |
| You pass `null` | **Do not do this** — causes a double separator and signature mismatch |

---

### Legacy Receipt Validation

> Apple has deprecated `verifyReceipt`. Use the App Store Server API for new integrations.

```php
use Kkxdev\AppleIap\Facades\AppleIap;
use Kkxdev\AppleIap\Exceptions\ReceiptValidationException;

try {
    $response = AppleIap::validateReceipt($base64EncodedReceiptData);

    if ($response->isValid()) {
        foreach ($response->latestReceiptInfo as $purchase) {
            echo $purchase->productId;
            echo $purchase->expiresDateAsDateTime()?->format('Y-m-d');

            if (!$purchase->isExpired() && !$purchase->isCancelled()) {
                // Active purchase
            }
        }

        // Find most recent purchase for a specific product
        $latest = $response->latestPurchaseFor('com.example.app.premium');
    }
} catch (ReceiptValidationException $e) {
    echo "Status {$e->getStatusCode()}: {$e->getMessage()}";
}
```

The package automatically retries against the sandbox endpoint when Apple returns status `21007` (sandbox receipt sent to production), so you do not need to handle this case yourself.

---

### Dependency Injection

All components are bound in the container. You can inject them directly:

```php
use Kkxdev\AppleIap\Contracts\AppStoreServerApiInterface;
use Kkxdev\AppleIap\Contracts\JwsVerifierInterface;
use Kkxdev\AppleIap\Contracts\NotificationVerifierInterface;
use Kkxdev\AppleIap\Contracts\ReceiptValidatorInterface;

class SubscriptionService
{
    public function __construct(
        private AppStoreServerApiInterface $serverApi,
        private JwsVerifierInterface $jwsVerifier,
    ) {}
}
```

---

### Artisan Command

Verify a receipt from the command line (useful for debugging customer issues):

```bash
php artisan apple-iap:verify-receipt <base64-receipt-data>

# Against sandbox
php artisan apple-iap:verify-receipt <receipt> --env=sandbox

# Override shared secret
php artisan apple-iap:verify-receipt <receipt> --shared-secret=xxxx
```

---

## Error Handling

All exceptions extend `Kkxdev\AppleIap\Exceptions\AppleIapException`:

| Exception | Thrown when |
|---|---|
| `ReceiptValidationException` | Apple returns a non-zero receipt validation status |
| `JwsVerificationException` | A JWS token fails certificate chain or signature verification |
| `NotificationVerificationException` | A server notification payload fails verification |
| `ApiException` | App Store Server API returns a 4xx response |
| `NetworkException` | Connection failure or 5xx response from Apple |
| `CircuitBreakerOpenException` | Circuit breaker is open due to repeated failures |
| `InvalidEnvironmentException` | An invalid environment value is configured |

```php
use Kkxdev\AppleIap\Exceptions\AppleIapException;
use Kkxdev\AppleIap\Exceptions\CircuitBreakerOpenException;
use Kkxdev\AppleIap\Exceptions\NetworkException;

try {
    $statuses = AppleIap::getAllSubscriptionStatuses($originalTransactionId);
} catch (CircuitBreakerOpenException $e) {
    // Apple API is temporarily unavailable — return cached state
    return Cache::get("subscription:{$userId}");
} catch (NetworkException $e) {
    // Transient network failure
    Log::warning('Apple IAP network failure', ['error' => $e->getMessage()]);
} catch (AppleIapException $e) {
    // Any other package exception
    Log::error('Apple IAP error', ['error' => $e->getMessage()]);
}
```

---

## Circuit Breaker

The circuit breaker prevents cascading failures when Apple's APIs are experiencing issues.

**States:**
- **Closed** — normal operation; failures are counted
- **Open** — fail-fast; all requests throw `CircuitBreakerOpenException` immediately
- **Half-open** — one probe request is allowed through to test recovery

**What counts as a failure:** `NetworkException` only (5xx responses, connection timeouts). `ApiException` with 4xx status does not trip the circuit — those are caller errors.

Two independent circuit breakers run in parallel:
- `receipt_validation` — wraps calls to `verifyReceipt`
- `server_api` — wraps calls to the App Store Server API

To disable entirely:

```env
APPLE_IAP_CB_ENABLED=false
```

---

## Testing

The package binds all components through contracts, making them easy to swap in tests.

### Faking HTTP calls

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    '*/verifyReceipt' => Http::response([
        'status'      => 0,
        'environment' => 'Sandbox',
        'receipt'     => ['in_app' => []],
        'latest_receipt_info' => [
            [
                'product_id'              => 'com.example.premium',
                'transaction_id'          => 'tx-001',
                'original_transaction_id' => 'tx-001',
                'purchase_date_ms'        => (string)(time() * 1000),
                'original_purchase_date_ms' => (string)(time() * 1000),
                'expires_date_ms'         => (string)((time() + 2592000) * 1000),
                'quantity'                => '1',
                'is_trial_period'         => 'false',
                'is_in_intro_offer_period' => 'false',
            ],
        ],
    ], 200),
]);
```

### Faking events

```php
use Illuminate\Support\Facades\Event;
use Kkxdev\AppleIap\Events\SubscriptionRenewed;

Event::fake();

// ... trigger notification processing ...

Event::assertDispatched(SubscriptionRenewed::class, function ($event) use ($originalTransactionId) {
    return $event->transaction->originalTransactionId === $originalTransactionId;
});
```

### Mocking the verifier

```php
use Kkxdev\AppleIap\Contracts\NotificationVerifierInterface;
use Kkxdev\AppleIap\DTO\Notification\ServerNotification;

$mock = $this->mock(NotificationVerifierInterface::class);
$mock->shouldReceive('verify')
     ->once()
     ->andReturn($this->makeTestNotification());
```

---

## DTO Reference

### `JwsTransaction`

| Property | Type | Description |
|---|---|---|
| `transactionId` | `string` | Unique transaction identifier |
| `originalTransactionId` | `string` | Original transaction (stable across renewals) |
| `bundleId` | `string` | App bundle identifier |
| `productId` | `string` | Product identifier |
| `subscriptionGroupIdentifier` | `?string` | Subscription group |
| `purchaseDate` | `int` | Purchase timestamp in milliseconds |
| `originalPurchaseDate` | `int` | Original purchase timestamp in milliseconds |
| `expiresDate` | `?int` | Expiry timestamp in milliseconds (subscriptions only) |
| `quantity` | `int` | Quantity purchased |
| `type` | `string` | Product type (see `ProductType` constants) |
| `appAccountToken` | `?string` | UUID you associated with the user at purchase time |
| `inAppOwnershipType` | `string` | `PURCHASED` or `FAMILY_SHARED` |
| `environment` | `?string` | `Production` or `Sandbox` |
| `price` | `?int` | Price in milliunits of the currency |
| `currency` | `?string` | ISO 4217 currency code |
| `revocationDate` | `?int` | Set if the transaction was revoked |
| `revocationReason` | `?string` | Reason for revocation |
| `isUpgraded` | `bool` | Whether this was superseded by an upgrade |

Helper methods: `isExpired()`, `isRevoked()`, `isSandbox()`, `purchaseDateAsDateTime()`, `expiresDateAsDateTime()`

### `JwsRenewalInfo`

| Property | Type | Description |
|---|---|---|
| `originalTransactionId` | `string` | Links to the subscription |
| `productId` | `string` | Current product identifier |
| `autoRenewProductId` | `string` | Product to renew into |
| `autoRenewStatus` | `int` | `1` = will renew, `0` = won't |
| `isInBillingRetryPeriod` | `bool` | Payment failed; Apple retrying |
| `gracePeriodExpiresDate` | `?int` | Grace period end in milliseconds |
| `offerIdentifier` | `?string` | Applied offer code |
| `expirationIntent` | `?int` | Why the subscription expired |

Helper methods: `willAutoRenew()`, `isInGracePeriod()`, `gracePeriodExpiresDateAsDateTime()`

### `ProductType` constants

```php
use Kkxdev\AppleIap\DTO\Enums\ProductType;

ProductType::AUTO_RENEWABLE_SUBSCRIPTION  // 'Auto-Renewable Subscription'
ProductType::NON_CONSUMABLE               // 'Non-Consumable'
ProductType::CONSUMABLE                   // 'Consumable'
ProductType::NON_RENEWING_SUBSCRIPTION    // 'Non-Renewing Subscription'

ProductType::isSubscription($type); // true for auto-renewable and non-renewing
```

---

## Security

JWS verification uses the **Apple Root CA G3** certificate embedded in the package. No certificate is fetched at runtime. The package walks the full `x5c` certificate chain in every JWS token and verifies:

1. Each certificate in the chain is signed by the next.
2. The chain root matches the embedded Apple Root CA G3.
3. The JWS signature is valid using the leaf certificate's public key (ES256).

This means no Apple notification or transaction token can be forged, even if an attacker controls the network.

---

## License

MIT
