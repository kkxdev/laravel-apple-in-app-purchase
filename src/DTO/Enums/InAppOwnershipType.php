<?php

namespace Kkxdev\AppleIap\DTO\Enums;

/**
 * Indicates whether the purchase was made directly or through Family Sharing.
 */
final class InAppOwnershipType
{
    public const PURCHASED     = 'PURCHASED';
    public const FAMILY_SHARED = 'FAMILY_SHARED';

    private function __construct()
    {
    }
}
