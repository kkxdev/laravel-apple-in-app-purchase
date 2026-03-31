<?php

namespace Kkxdev\AppleIap\Events;

use Kkxdev\AppleIap\DTO\Receipt\ValidateReceiptResponse;

class ReceiptValidated
{
    public function __construct(
        public ValidateReceiptResponse $response,
    ) {
    }
}
