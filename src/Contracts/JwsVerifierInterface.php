<?php

namespace Kkxdev\AppleIap\Contracts;

interface JwsVerifierInterface
{
    /**
     * Verify the JWS token against Apple's certificate chain and return the decoded payload.
     *
     * @throws \Kkxdev\AppleIap\Exceptions\JwsVerificationException
     */
    public function verify(string $jwsToken): array;

    /**
     * Decode a JWS token without certificate chain verification.
     * Only use this for debugging — never trust the result in production.
     */
    public function decodeWithoutVerification(string $jwsToken): array;
}
