<?php

namespace App\Services\Sso;

final readonly class SsoClaims
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public int $characterId,
        public string $name,
        public string $ownerHash,
        public array $scopes,
    ) {}
}
