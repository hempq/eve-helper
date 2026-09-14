<?php

namespace App\Services\Esi;

use App\Models\Character;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Sso\AccessTokenProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class EsiClient implements EsiClientInterface
{
    private const BACKOFF_CACHE_KEY = 'esi:error-limit-backoff-until';

    /** Keep stale bodies around for ETag revalidation. */
    private const CACHE_TTL_SECONDS = 7 * 24 * 3600;

    public function __construct(
        private readonly AccessTokenProvider $tokens,
        private readonly Cache $cache,
        private readonly string $baseUrl,
        private readonly string $compatibilityDate,
        private readonly string $userAgent,
        private readonly int $errorLimitThreshold,
    ) {}

    public function get(string $path, array $query = [], ?Character $character = null): EsiResponse
    {
        $cacheKey = $this->cacheKey($path, $query, $character);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null && CarbonImmutable::parse($cached['expires_at'])->isFuture()) {
            return $this->responseFromCache($cached);
        }

        $this->assertErrorBudgetAvailable();

        $response = $this->pendingRequest($character, $cached['etag'] ?? null)
            ->get($this->baseUrl.$path, $query);

        $this->trackErrorBudget($response);
        $this->trackRateLimit($response);

        if ($response->status() === 304 && $cached !== null) {
            $cached['expires_at'] = $this->expiresAt($response)->toIso8601String();
            $this->cache->put($cacheKey, $cached, self::CACHE_TTL_SECONDS);

            return $this->responseFromCache($cached);
        }

        $this->assertNotRateLimited($response);

        if ($response->failed()) {
            throw EsiRequestFailed::fromResponse($path, $response);
        }

        $json = $response->json();

        $fresh = [
            // Scalar responses (e.g. the wallet balance) are wrapped so
            // EsiResponse::$data is always an array.
            'data' => is_array($json) ? $json : [$json],
            'etag' => $response->header('ETag') ?: null,
            'expires_at' => $this->expiresAt($response)->toIso8601String(),
            'pages' => max(1, (int) $response->header('X-Pages', '1')),
        ];
        $this->cache->put($cacheKey, $fresh, self::CACHE_TTL_SECONDS);

        return new EsiResponse(
            data: $fresh['data'],
            expiresAt: CarbonImmutable::parse($fresh['expires_at']),
            pages: $fresh['pages'],
        );
    }

    public function post(string $path, array $query, Character $character): void
    {
        $this->assertErrorBudgetAvailable();

        $response = $this->pendingRequest($character, null)
            ->post($this->baseUrl.$path.'?'.http_build_query($query));

        $this->trackErrorBudget($response);
        $this->trackRateLimit($response);
        $this->assertNotRateLimited($response);

        if ($response->failed()) {
            throw EsiRequestFailed::fromResponse($path, $response);
        }
    }

    public function getAllPages(string $path, array $query = [], ?Character $character = null): array
    {
        $first = $this->get($path, [...$query, 'page' => 1], $character);
        $rows = $first->data;

        for ($page = 2; $page <= $first->pages; $page++) {
            $rows = [...$rows, ...$this->get($path, [...$query, 'page' => $page], $character)->data];
        }

        return $rows;
    }

    private function pendingRequest(?Character $character, ?string $etag): PendingRequest
    {
        $request = Http::withHeaders([
            'User-Agent' => $this->userAgent,
            'X-Compatibility-Date' => $this->compatibilityDate,
            'Accept' => 'application/json',
        ])->connectTimeout(5)->timeout(20);

        if ($etag !== null) {
            $request->withHeader('If-None-Match', $etag);
        }

        if ($character !== null) {
            $request->withToken($this->tokens->tokenFor($character));
        }

        return $request;
    }

    private function assertErrorBudgetAvailable(): void
    {
        $backoffUntil = $this->cache->get(self::BACKOFF_CACHE_KEY);

        if ($backoffUntil !== null && CarbonImmutable::parse($backoffUntil)->isFuture()) {
            throw new EsiErrorLimited(
                (int) ceil(now()->diffInSeconds(CarbonImmutable::parse($backoffUntil), true)),
            );
        }
    }

    private function trackErrorBudget(Response $response): void
    {
        $remain = $response->header('X-Esi-Error-Limit-Remain');

        if ($remain !== '' && (int) $remain <= $this->errorLimitThreshold) {
            $reset = (int) ($response->header('X-Esi-Error-Limit-Reset') ?: 60);
            $this->cache->put(
                self::BACKOFF_CACHE_KEY,
                now()->addSeconds($reset)->toIso8601String(),
                $reset,
            );
        }
    }

    /**
     * The 2025 token-bucket limiter: back off when few tokens remain in the
     * 15-minute window (X-Ratelimit-Remaining), before hitting a 429.
     */
    private function trackRateLimit(Response $response): void
    {
        $remaining = $response->header('X-Ratelimit-Remaining');

        if ($remaining !== '' && (int) $remaining <= 5) {
            // The window is stated as e.g. "150/15m"; back off to its end.
            $seconds = (int) ($response->header('Retry-After') ?: 60);
            $this->cache->put(
                self::BACKOFF_CACHE_KEY,
                now()->addSeconds($seconds)->toIso8601String(),
                $seconds,
            );
        }
    }

    private function assertNotRateLimited(Response $response): void
    {
        if ($response->status() === 420) {
            throw new EsiErrorLimited((int) $response->header('X-Esi-Error-Limit-Reset', '60'));
        }

        if ($response->status() === 429) {
            $retry = (int) ($response->header('Retry-After') ?: 60);
            $this->cache->put(self::BACKOFF_CACHE_KEY, now()->addSeconds($retry)->toIso8601String(), $retry);

            throw new EsiErrorLimited($retry);
        }
    }

    private function expiresAt(Response $response): CarbonImmutable
    {
        $expires = $response->header('Expires');

        return $expires !== ''
            ? CarbonImmutable::parse($expires)
            : CarbonImmutable::now();
    }

    private function cacheKey(string $path, array $query, ?Character $character): string
    {
        ksort($query);

        return 'esi:'.md5(implode('|', [
            $path,
            http_build_query($query),
            $character?->character_id ?? 'public',
        ]));
    }

    private function responseFromCache(array $cached): EsiResponse
    {
        return new EsiResponse(
            data: $cached['data'],
            expiresAt: CarbonImmutable::parse($cached['expires_at']),
            pages: $cached['pages'] ?? 1,
            fromCache: true,
        );
    }
}
