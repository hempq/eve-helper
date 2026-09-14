<?php

namespace App\Services\Sso;

use Carbon\CarbonImmutable;

final readonly class EveTokens
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public CarbonImmutable $expiresAt,
    ) {}

    /**
     * @param  array{access_token: string, refresh_token: string, expires_in: int}  $payload
     */
    public static function fromTokenResponse(array $payload): self
    {
        return new self(
            accessToken: $payload['access_token'],
            refreshToken: $payload['refresh_token'],
            expiresAt: CarbonImmutable::now()->addSeconds($payload['expires_in']),
        );
    }
}
