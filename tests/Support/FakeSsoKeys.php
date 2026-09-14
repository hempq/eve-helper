<?php

namespace Tests\Support;

use Firebase\JWT\JWT;

/**
 * Generates a throwaway RSA keypair so tests can mint EVE-SSO-shaped JWTs
 * and the matching JWKS document the validator will fetch.
 */
final class FakeSsoKeys
{
    public const KID = 'test-key-1';

    private function __construct(
        public readonly string $privateKeyPem,
        public readonly array $jwks,
    ) {}

    public static function generate(): self
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $privateKeyPem);
        $details = openssl_pkey_get_details($key);

        $jwks = ['keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => self::KID,
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ]]];

        return new self($privateKeyPem, $jwks);
    }

    public function issueToken(array $claimOverrides = []): string
    {
        $claims = array_merge([
            'iss' => 'https://login.eveonline.com',
            'sub' => 'CHARACTER:EVE:91234567',
            'aud' => ['test-client-id', 'EVE Online'],
            'name' => 'Test Pilot',
            'owner' => 'ownerhash123=',
            'scp' => ['esi-skills.read_skills.v1'],
            'exp' => time() + 1200,
            'iat' => time(),
        ], $claimOverrides);

        return JWT::encode($claims, $this->privateKeyPem, 'RS256', self::KID);
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
