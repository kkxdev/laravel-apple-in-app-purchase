<?php

namespace Kkxdev\AppleIap\Events;

use Kkxdev\AppleIap\DTO\Transaction\JwsTransaction;

class TransactionVerified
{
    public function __construct(
        public JwsTransaction $transaction,
    ) {
    }
}
