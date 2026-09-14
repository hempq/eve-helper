<?php

namespace App\Services\Sso;

use App\Models\Character;

class AccessTokenManager implements AccessTokenProvider
{
    /**
     * Refresh this long before the stored expiry, so a token never dies
     * mid-request.
     */
    private const EXPIRY_MARGIN_SECONDS = 60;

    public function __construct(private readonly EveSsoService $sso) {}

    public function tokenFor(Character $character): string
    {
        if ($this->hasUsableToken($character)) {
            return $character->access_token;
        }

        $tokens = $this->sso->refresh($character->refresh_token);

        $character->forceFill([
            'access_token' => $tokens->accessToken,
            'access_token_expires_at' => $tokens->expiresAt,
            'refresh_token' => $tokens->refreshToken,
        ])->save();

        return $tokens->accessToken;
    }

    private function hasUsableToken(Character $character): bool
    {
        return $character->access_token !== null
            && $character->access_token_expires_at !== null
            && $character->access_token_expires_at->subSeconds(self::EXPIRY_MARGIN_SECONDS)->isFuture();
    }
}
