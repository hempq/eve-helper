<?php

namespace App\Services\Sso;

use Illuminate\Support\Facades\Http;

class EveSsoService
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        private readonly JwtValidator $jwtValidator,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $authorizeUrl,
        private readonly string $tokenUrl,
        private readonly array $scopes,
    ) {}

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return $this->authorizeUrl.'?'.http_build_query([
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'scope' => implode(' ', $this->scopes),
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): EveTokens
    {
        return $this->requestTokens([
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);
    }

    public function refresh(string $refreshToken): EveTokens
    {
        return $this->requestTokens([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public function claims(string $accessToken): SsoClaims
    {
        return $this->jwtValidator->validate($accessToken);
    }

    private function requestTokens(array $params): EveTokens
    {
        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->post($this->tokenUrl, $params)
            ->throw();

        return EveTokens::fromTokenResponse($response->json());
    }
}
