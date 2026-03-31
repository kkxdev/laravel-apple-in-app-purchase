<?php

namespace Kkxdev\AppleIap\Facades;

use Illuminate\Support\Facades\Facade;
use Kkxdev\AppleIap\AppleIapManager;
use Kkxdev\AppleIap\DTO\Notification\ServerNotification;
use Kkxdev\AppleIap\DTO\Receipt\ValidateReceiptResponse;
use Kkxdev\AppleIap\DTO\ServerApi\AllSubscriptionStatusesResponse;
use Kkxdev\AppleIap\DTO\ServerApi\ExtendRenewalDateRequest;
use Kkxdev\AppleIap\DTO\ServerApi\ExtendRenewalDateResponse;
use Kkxdev\AppleIap\DTO\ServerApi\RefundLookupResponse;
use Kkxdev\AppleIap\DTO\ServerApi\TransactionHistoryRequest;
use Kkxdev\AppleIap\DTO\ServerApi\TransactionHistoryResponse;
use Kkxdev\AppleIap\DTO\Transaction\JwsRenewalInfo;
use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;

/**
 * @method static ValidateReceiptResponse validateReceipt(string|array $receiptData, ?string $sharedSecret = null, bool $excludeOldTransactions = false)
 * @method static JwsTransaction decodeTransaction(string $signedTransaction)
 * @method static JwsRenewalInfo decodeRenewalInfo(string $signedRenewalInfo)
 * @method static ServerNotification processServerNotification(string $signedPayload)
 * @method static TransactionHistoryResponse getTransactionHistory(string $originalTransactionId, ?TransactionHistoryRequest $params = null)
 * @method static TransactionHistoryResponse getTransactionHistoryV2(string $originalTransactionId, ?TransactionHistoryRequest $params = null)
 * @method static AllSubscriptionStatusesResponse getAllSubscriptionStatuses(string $originalTransactionId)
 * @method static TransactionHistoryResponse lookUpOrderId(string $orderId)
 * @method static RefundLookupResponse getRefundHistory(string $originalTransactionId)
 * @method static ExtendRenewalDateResponse extendSubscriptionRenewalDate(string $originalTransactionId, ExtendRenewalDateRequest $request)
 * @method static string sendTestNotification()
 * @method static array getTestNotificationStatus(string $testNotificationToken)
 *
 * @see AppleIapManager
 */
class AppleIap extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'apple-iap';
    }
}
