<?php

namespace Kkxdev\AppleIap\Contracts;

use Kkxdev\AppleIap\DTO\ServerApi\AllSubscriptionStatusesResponse;
use Kkxdev\AppleIap\DTO\ServerApi\ExtendRenewalDateRequest;
use Kkxdev\AppleIap\DTO\ServerApi\ExtendRenewalDateResponse;
use Kkxdev\AppleIap\DTO\ServerApi\RefundLookupResponse;
use Kkxdev\AppleIap\DTO\ServerApi\TransactionHistoryRequest;
use Kkxdev\AppleIap\DTO\ServerApi\TransactionHistoryResponse;

interface AppStoreServerApiInterface
{
    public function getTransactionHistory(
        string $originalTransactionId,
        ?TransactionHistoryRequest $params = null
    ): TransactionHistoryResponse;

    public function getTransactionHistoryV2(
        string $originalTransactionId,
        ?TransactionHistoryRequest $params = null
    ): TransactionHistoryResponse;

    public function getAllSubscriptionStatuses(string $originalTransactionId): AllSubscriptionStatusesResponse;

    public function lookUpOrderId(string $orderId): TransactionHistoryResponse;

    public function getRefundHistory(string $originalTransactionId): RefundLookupResponse;

    public function extendSubscriptionRenewalDate(
        string $originalTransactionId,
        ExtendRenewalDateRequest $request
    ): ExtendRenewalDateResponse;

    public function sendTestNotification(): string;

    public function getTestNotificationStatus(string $testNotificationToken): array;
}
