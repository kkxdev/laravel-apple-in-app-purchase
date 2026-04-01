<?php

namespace Kkxdev\AppleIap\Crypto;

/**
 * Embeds the Apple Root CA G3 PEM certificate.
 *
 * This is the trust anchor for verifying Apple-signed JWS tokens
 * (StoreKit 2 transactions, App Store Server API, server notifications v2).
 * Sourced from: https://www.apple.com/certificateauthority/
 *
 * Apple Root CA - G3
 * SHA-256 Fingerprint: 63:34:3A:BF:B8:9A:6A:03:EB:B5:7E:9B:3F:5F:A7:BE:
 *                      7C:4F:5C:75:6F:30:17:B3:A8:C4:88:C3:65:3E:91:79
 *
 * NOTE: Apple has never rotated this root certificate. If they do,
 * a new package release is required to update this constant.
 */
final class AppleRootCertificateBundle
{
    /**
     * Apple Root CA - G3 (used by StoreKit 2 / App Store Server Notifications v2)
     */
    public const APPLE_ROOT_CA_G3 = <<<PEM
-----BEGIN CERTIFICATE-----
MIICQzCCAcmgAwIBAgIILcX8iNLFS5UwCgYIKoZIzj0EAwMwZzEbMBkGA1UEAwwS
QXBwbGUgUm9vdCBDQSAtIEczMSYwJAYDVQQLDB1BcHBsZSBDZXJ0aWZpY2F0aW9u
IEF1dGhvcml0eTETMBEGA1UECgwKQXBwbGUgSW5jLjELMAkGA1UEBhMCVVMwHhcN
MTQwNDMwMTgxOTA2WhcNMzkwNDMwMTgxOTA2WjBnMRswGQYDVQQDDBJBcHBsZSBS
b290IENBIC0gRzMxJjAkBgNVBAsMHUFwcGxlIENlcnRpZmljYXRpb24gQXV0aG9y
aXR5MRMwEQYDVQQKDApBcHBsZSBJbmMuMQswCQYDVQQGEwJVUzB2MBAGByqGSM49
AgEGBSuBBAAiA2IABJjpLz1AcqTtkyJygRMc3RCV8cWjTnHcFBbZDuWmBSp3ZHtf
TjjTuxxEtX/1H7YyYl3J6YRbTzBPEVoA/VhYDKX1DyxNB0cTddqXl5dvMVztK517
IDvYuVTZXpmkOlEKMaNCMEAwHQYDVR0OBBYEFLuw3qFYM4iapIqZ3r6966/ayySr
MA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgEGMAoGCCqGSM49BAMDA2gA
MGUCMQCD6cHEFl4aXTQY2e3v9GwOAEZLuN+yRhHFD/3meoyhpmvOwgPUnPWTxnS4
at+qIxUCMG1mihDK1A3UT82NQz60imOlM27jbdoXt2QfyFMm+YhidDkLF1vLUagM
6BgD56KyKA==
-----END CERTIFICATE-----
PEM;

    /**
     * Returns all trusted root CA PEM strings as an array.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::APPLE_ROOT_CA_G3,
        ];
    }

    private function __construct()
    {
    }
}
