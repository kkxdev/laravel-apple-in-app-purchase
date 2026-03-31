<?php

namespace Kkxdev\AppleIap\Console\Commands;

use Illuminate\Console\Command;
use Kkxdev\AppleIap\Contracts\ReceiptValidatorInterface;
use Kkxdev\AppleIap\DTO\Receipt\ValidateReceiptRequest;
use Kkxdev\AppleIap\Exceptions\ReceiptValidationException;

class VerifyReceiptCommand extends Command
{
    protected $signature = 'apple-iap:verify-receipt
                            {receipt : Base64-encoded receipt data}
                            {--env=production : Environment to validate against (production|sandbox)}
                            {--shared-secret= : Override the shared secret from config}';

    protected $description = 'Validate an Apple App Store receipt and display the decoded purchase records.';

    public function handle(ReceiptValidatorInterface $validator): int
    {
        $receiptData   = $this->argument('receipt');
        $sharedSecret  = $this->option('shared-secret');

        $request = new ValidateReceiptRequest(
            receiptData:            $receiptData,
            sharedSecret:           $sharedSecret ?: null,
            excludeOldTransactions: false,
        );

        $this->info('Validating receipt...');

        try {
            $response = $validator->validate($request);
        } catch (ReceiptValidationException $e) {
            $this->error("Validation failed (status {$e->getStatusCode()}): {$e->getMessage()}");
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Request failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info("Status: {$response->status} | Environment: {$response->environment}");

        if (empty($response->latestReceiptInfo) && empty($response->inApp)) {
            $this->warn('No in-app purchase records found in receipt.');
            return self::SUCCESS;
        }

        $records = !empty($response->latestReceiptInfo) ? $response->latestReceiptInfo : $response->inApp;

        $rows = array_map(static fn ($p) => [
            $p->productId,
            $p->transactionId,
            $p->originalTransactionId,
            $p->purchaseDateAsDateTime()->format('Y-m-d H:i:s'),
            $p->expiresDateAsDateTime()?->format('Y-m-d H:i:s') ?? 'N/A',
            $p->isExpired() ? 'Expired' : ($p->isCancelled() ? 'Cancelled' : 'Active'),
        ], $records);

        $this->table(
            ['Product ID', 'Transaction ID', 'Original Transaction ID', 'Purchase Date', 'Expires Date', 'Status'],
            $rows
        );

        return self::SUCCESS;
    }
}
