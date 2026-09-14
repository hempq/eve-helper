<?php

namespace Tests\Feature\Esi;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Sso\AccessTokenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EsiClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eve.esi.user_agent' => 'EveHelperTest/1.0 (test@example.com)',
            'eve.esi.compatibility_date' => '2026-09-01',
        ]);
    }

    private function client(): EsiClientInterface
    {
        return $this->app->make(EsiClientInterface::class);
    }

    public function test_sends_required_headers_and_returns_data(): void
    {
        Http::fake([
            'esi.evetech.net/*' => Http::response(
                ['solar_system_id' => 30000142],
                200,
                ['Expires' => now()->addMinutes(5)->toRfc7231String(), 'ETag' => '"abc"'],
            ),
        ]);

        $response = $this->client()->get('/universe/systems/30000142');

        $this->assertSame(30000142, $response->data['solar_system_id']);
        $this->assertFalse($response->fromCache);

        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'EveHelperTest/1.0 (test@example.com)')
            && $request->hasHeader('X-Compatibility-Date', '2026-09-01')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_serves_from_cache_until_expires(): void
    {
        Http::fake([
            'esi.evetech.net/*' => Http::response(
                ['value' => 1],
                200,
                ['Expires' => now()->addMinutes(5)->toRfc7231String()],
            ),
        ]);

        $first = $this->client()->get('/markets/prices');
        $second = $this->client()->get('/markets/prices');

        $this->assertFalse($first->fromCache);
        $this->assertTrue($second->fromCache);
        $this->assertSame($first->data, $second->data);
        Http::assertSentCount(1);
    }

    public function test_revalidates_with_etag_and_reuses_body_on_304(): void
    {
        Http::fakeSequence('esi.evetech.net/*')
            ->push(['value' => 42], 200, [
                'Expires' => now()->subSecond()->toRfc7231String(), // already stale
                'ETag' => '"v1"',
            ])
            ->pushStatus(304, ['Expires' => now()->addMinutes(5)->toRfc7231String()]);

        $client = $this->client();
        $first = $client->get('/universe/systems/30000142');
        $second = $client->get('/universe/systems/30000142');

        $this->assertSame(['value' => 42], $second->data);
        $this->assertTrue($second->fromCache);

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            static $call = 0;

            return ++$call === 2
                ? $request->hasHeader('If-None-Match', '"v1"')
                : true;
        });
    }

    public function test_authenticated_request_carries_bearer_token(): void
    {
        $this->mock(AccessTokenProvider::class)
            ->shouldReceive('tokenFor')
            ->once()
            ->andReturn('the-access-token');

        Http::fake([
            'esi.evetech.net/*' => Http::response(['total_sp' => 5_000_000], 200),
        ]);

        $character = Character::factory()->create();
        $response = $this->client()->get('/characters/'.$character->character_id.'/skills', [], $character);

        $this->assertSame(5_000_000, $response->data['total_sp']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer the-access-token'));
    }

    public function test_reads_page_count_from_headers(): void
    {
        Http::fake([
            'esi.evetech.net/*' => Http::response([['order_id' => 1]], 200, ['X-Pages' => '37']),
        ]);

        $response = $this->client()->get('/markets/10000002/orders', ['type_id' => 34]);

        $this->assertSame(37, $response->pages);
    }

    public function test_throws_on_http_420_error_limited(): void
    {
        Http::fake([
            'esi.evetech.net/*' => Http::response(
                ['error' => 'error limited'],
                420,
                ['X-Esi-Error-Limit-Reset' => '42'],
            ),
        ]);

        try {
            $this->client()->get('/universe/systems/30000142');
            $this->fail('Expected EsiErrorLimited.');
        } catch (EsiErrorLimited $e) {
            $this->assertSame(42, $e->retryAfterSeconds);
        }
    }

    public function test_backs_off_locally_when_error_budget_runs_low(): void
    {
        Http::fake([
            'esi.evetech.net/*' => Http::response(['error' => 'not found'], 404, [
                'X-Esi-Error-Limit-Remain' => '5',
                'X-Esi-Error-Limit-Reset' => '30',
            ]),
        ]);

        $client = $this->client();

        try {
            $client->get('/universe/systems/1');
            $this->fail('Expected EsiRequestFailed.');
        } catch (EsiRequestFailed) {
            // expected: the 404 itself
        }

        // The next call must not reach ESI at all — budget is nearly spent.
        $this->expectException(EsiErrorLimited::class);
        $client->get('/universe/systems/2');

        Http::assertSentCount(1);
    }

    public function test_throws_on_http_429_with_retry_after(): void
    {
        Http::fake([
            'esi.evetech.net/*' => Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '30']),
        ]);

        try {
            $this->client()->get('/universe/systems/30000142');
            $this->fail('Expected EsiErrorLimited.');
        } catch (EsiErrorLimited $e) {
            $this->assertSame(30, $e->retryAfterSeconds);
        }

        // The backoff now blocks the next call without touching ESI.
        $this->expectException(EsiErrorLimited::class);
        $this->client()->get('/universe/systems/1');
    }

    public function test_backs_off_when_ratelimit_tokens_run_low(): void
    {
        Http::fake([
            'esi.evetech.net/*' => Http::response(['ok' => true], 200, [
                'X-Ratelimit-Remaining' => '3',
                'Retry-After' => '45',
                'Expires' => now()->addMinutes(5)->toRfc7231String(),
            ]),
        ]);

        $this->client()->get('/status'); // succeeds but nearly out of tokens

        $this->expectException(EsiErrorLimited::class);
        $this->client()->get('/markets/prices');
    }

    public function test_throws_esi_request_failed_on_client_error(): void
    {
        Http::fake([
            'esi.evetech.net/*' => Http::response(['error' => 'Character not found'], 404),
        ]);

        $this->expectException(EsiRequestFailed::class);
        $this->expectExceptionMessage('Character not found');

        $this->client()->get('/characters/1/skills');
    }
}
