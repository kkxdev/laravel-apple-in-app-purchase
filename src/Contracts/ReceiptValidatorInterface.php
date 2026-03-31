<?php

namespace Kkxdev\AppleIap\Contracts;

use Kkxdev\AppleIap\DTO\Receipt\ValidateReceiptRequest;
use Kkxdev\AppleIap\DTO\Receipt\ValidateReceiptResponse;

interface ReceiptValidatorInterface
{
    public function validate(ValidateReceiptRequest $request): ValidateReceiptResponse;
}
