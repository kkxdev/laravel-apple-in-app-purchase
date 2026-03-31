<?php

namespace Kkxdev\AppleIap\Crypto;

use Kkxdev\AppleIap\Contracts\JwsVerifierInterface;
use Kkxdev\AppleIap\Exceptions\JwsVerificationException;

/**
 * Verifies Apple-signed JWS tokens (transactions, renewal info, notification payloads).
 *
 * Apple signs all JWS tokens with:
 *  - An ES256 (ECDSA P-384) algorithm
 *  - An x5c header containing the certificate chain: [leaf, intermediate, root]
 *  - The chain terminates at Apple Root CA G3 (embedded in AppleRootCertificateBundle)
 *
 * Verification steps:
 *  1. Parse the JWS header to extract the x5c certificate chain.
 *  2. Walk the chain: each certificate must be signed by the next one.
 *  3. The last certificate in the chain must match the embedded Apple Root CA.
 *  4. Verify the JWS signature using the leaf certificate's public key.
 *  5. Return the decoded payload.
 */
class JwsVerifier implements JwsVerifierInterface
{
    public function verify(string $jwsToken): array
    {
        [$headerB64, $payloadB64, $signatureB64] = $this->splitToken($jwsToken);

        $header = $this->decodeJson($this->base64UrlDecode($headerB64), 'JWS header');

        if (($header['alg'] ?? '') !== 'ES256') {
            throw new JwsVerificationException(
                "Unsupported JWS algorithm: " . ($header['alg'] ?? 'none') . ". Expected ES256."
            );
        }

        if (empty($header['x5c']) || !is_array($header['x5c'])) {
            throw new JwsVerificationException('JWS header is missing the x5c certificate chain.');
        }

        $certChain = $this->parseCertificateChain($header['x5c']);

        $this->verifyCertificateChain($certChain);

        $leafCertResource = openssl_x509_read($certChain[0]);
        if ($leafCertResource === false) {
            throw new JwsVerificationException('Failed to read leaf certificate from JWS x5c header.');
        }

        $publicKeyResource = openssl_pkey_get_public($leafCertResource);
        if ($publicKeyResource === false) {
            throw new JwsVerificationException('Failed to extract public key from leaf certificate.');
        }

        $signedData  = "{$headerB64}.{$payloadB64}";
        $signature   = $this->base64UrlDecode($signatureB64);
        $derSignature = $this->ecSignatureToDer($signature);

        $verified = openssl_verify($signedData, $derSignature, $publicKeyResource, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            throw new JwsVerificationException('JWS signature verification failed.');
        }

        return $this->decodeJson($this->base64UrlDecode($payloadB64), 'JWS payload');
    }

    public function decodeWithoutVerification(string $jwsToken): array
    {
        [, $payloadB64] = $this->splitToken($jwsToken);

        return $this->decodeJson($this->base64UrlDecode($payloadB64), 'JWS payload');
    }

    /**
     * @return string[] Three parts: header, payload, signature (all base64url-encoded)
     */
    private function splitToken(string $jwsToken): array
    {
        $parts = explode('.', $jwsToken);

        if (count($parts) !== 3) {
            throw new JwsVerificationException('Invalid JWS token format. Expected 3 dot-separated parts.');
        }

        return $parts;
    }

    /**
     * Convert base64url-encoded DER certificates in x5c to PEM strings.
     *
     * @param string[] $x5c
     * @return string[] PEM-encoded certificates
     */
    private function parseCertificateChain(array $x5c): array
    {
        return array_map(function (string $derB64): string {
            $pem  = "-----BEGIN CERTIFICATE-----\n";
            $pem .= chunk_split($derB64, 64, "\n");
            $pem .= "-----END CERTIFICATE-----\n";
            return $pem;
        }, $x5c);
    }

    /**
     * Verify the certificate chain:
     *  - Each cert is signed by the next cert in the chain.
     *  - The root (last cert) must match a trusted Apple root CA.
     *
     * @param string[] $certChain PEM-encoded certificates [leaf, intermediate, ...]
     */
    private function verifyCertificateChain(array $certChain): void
    {
        if (count($certChain) < 2) {
            throw new JwsVerificationException(
                'JWS x5c certificate chain must contain at least 2 certificates (leaf + intermediate).'
            );
        }

        // Verify that the root in the chain matches a known Apple root CA.
        $chainRoot = $certChain[count($certChain) - 1];
        $this->verifyAgainstTrustedRoots($chainRoot);

        // Verify each certificate is signed by the next one in the chain.
        for ($i = 0; $i < count($certChain) - 1; $i++) {
            $subject  = $certChain[$i];
            $issuer   = $certChain[$i + 1];

            $issuerResource = openssl_x509_read($issuer);
            if ($issuerResource === false) {
                throw new JwsVerificationException("Failed to parse certificate at chain index {$i}.");
            }

            $issuerPublicKey = openssl_pkey_get_public($issuerResource);
            if ($issuerPublicKey === false) {
                throw new JwsVerificationException("Failed to extract public key from issuer cert at index {$i}.");
            }

            $result = openssl_x509_verify($subject, $issuerPublicKey);

            if ($result !== 1) {
                throw new JwsVerificationException(
                    "Certificate chain verification failed at index {$i}. "
                    . "Certificate was not signed by its issuer."
                );
            }
        }
    }

    /**
     * Compare the chain's root certificate against the embedded Apple root CAs.
     */
    private function verifyAgainstTrustedRoots(string $chainRootPem): void
    {
        $chainRootInfo = openssl_x509_parse($chainRootPem);
        if ($chainRootInfo === false) {
            throw new JwsVerificationException('Failed to parse root certificate from JWS x5c chain.');
        }

        foreach (AppleRootCertificateBundle::all() as $trustedRootPem) {
            $trustedInfo = openssl_x509_parse($trustedRootPem);
            if ($trustedInfo === false) {
                continue;
            }

            // Compare subject and public key fingerprints
            if ($chainRootInfo['subject'] === $trustedInfo['subject']) {
                $chainResource   = openssl_x509_read($chainRootPem);
                $trustedResource = openssl_x509_read($trustedRootPem);

                if ($chainResource === false || $trustedResource === false) {
                    continue;
                }

                $chainKey   = openssl_pkey_get_public($chainResource);
                $trustedKey = openssl_pkey_get_public($trustedResource);

                if ($chainKey === false || $trustedKey === false) {
                    continue;
                }

                $chainKeyDetails   = openssl_pkey_get_details($chainKey);
                $trustedKeyDetails = openssl_pkey_get_details($trustedKey);

                if ($chainKeyDetails['key'] === $trustedKeyDetails['key']) {
                    return; // Chain root matches a trusted Apple root CA
                }
            }
        }

        throw new JwsVerificationException(
            'JWS certificate chain root does not match any known Apple Root CA. '
            . 'This token was not issued by Apple.'
        );
    }

    /**
     * Convert a raw ECDSA signature (r||s, 64 bytes) to DER format for openssl_verify.
     *
     * Apple uses IEEE P1363 format (r||s) but OpenSSL expects DER/ASN.1 SEQUENCE.
     */
    private function ecSignatureToDer(string $rawSignature): string
    {
        $len = strlen($rawSignature);

        if ($len !== 64) {
            // Not a standard ES256 signature length; return as-is and let OpenSSL error.
            return $rawSignature;
        }

        $r = substr($rawSignature, 0, 32);
        $s = substr($rawSignature, 32, 32);

        // Remove leading zero bytes and re-add a zero byte if high bit is set (unsigned big-int in DER)
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        if (ord($r[0]) > 0x7F) {
            $r = "\x00" . $r;
        }
        if (ord($s[0]) > 0x7F) {
            $s = "\x00" . $s;
        }

        $rLen = strlen($r);
        $sLen = strlen($s);

        $derR = "\x02" . chr($rLen) . $r;
        $derS = "\x02" . chr($sLen) . $s;

        $seqLen = strlen($derR) + strlen($derS);

        return "\x30" . chr($seqLen) . $derR . $derS;
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            throw new JwsVerificationException('Failed to base64url-decode JWS component.');
        }

        return $decoded;
    }

    private function decodeJson(string $json, string $context): array
    {
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new JwsVerificationException(
                "Failed to JSON-decode {$context}: " . json_last_error_msg()
            );
        }

        return $decoded;
    }
}
