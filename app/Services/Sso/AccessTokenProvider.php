<?php

namespace App\Services\Sso;

use App\Models\Character;

interface AccessTokenProvider
{
    /**
     * Return a currently valid access token for the character, refreshing
     * (and persisting the possibly rotated refresh token) when needed.
     */
    public function tokenFor(Character $character): string;
}
