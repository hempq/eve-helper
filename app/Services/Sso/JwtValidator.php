<?php

namespace App\Services\Sso;

use App\Services\Sso\Exceptions\InvalidSsoToken;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Validates EVE SSO access tokens (JWTs) locally against the login server's
 * JWKS, as CCP recommends; the legacy /verify endpoint is being removed.
 */
class JwtValidator
{
    private const JWKS_CACHE_KEY = 'eve:sso:jwks';

    private const JWKS_CACHE_TTL = 3600;

    /**
     * @param  list<string>  $issuers
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly string $jwksUrl,
        private readonly string $clientId,
        private readonly array $issuers,
    ) {}

    public function validate(string $jwt): SsoClaims
    {
        try {
            $claims = JWT::decode($jwt, JWK::parseKeySet($this->jwks()));
        } catch (Throwable $e) {
            throw new InvalidSsoToken('SSO token signature/expiry validation failed: '.$e->getMessage(), previous: $e);
        }

        if (! in_array($claims->iss ?? '', $this->issuers, true)) {
            throw new InvalidSsoToken('Unexpected SSO token issuer: '.($claims->iss ?? 'missing'));
        }

        $audiences = (array) ($claims->aud ?? []);
        if (! in_array($this->clientId, $audiences, true)) {
            throw new InvalidSsoToken('SSO token was not issued for this application.');
        }

        if (! preg_match('/^CHARACTER:EVE:(\d+)$/', $claims->sub ?? '', $matches)) {
            throw new InvalidSsoToken('SSO token subject is not an EVE character.');
        }

        return new SsoClaims(
            characterId: (int) $matches[1],
            name: $claims->name ?? '',
            ownerHash: $claims->owner ?? '',
            scopes: array_values((array) ($claims->scp ?? [])),
        );
    }

    private function jwks(): array
    {
        return $this->cache->remember(
            self::JWKS_CACHE_KEY,
            self::JWKS_CACHE_TTL,
            fn (): array => Http::get($this->jwksUrl)->throw()->json(),
        );
    }
}
