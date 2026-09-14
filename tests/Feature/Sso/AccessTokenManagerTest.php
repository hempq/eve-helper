<?php

namespace Tests\Feature\Sso;

use App\Models\Character;
use App\Services\Sso\AccessTokenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccessTokenManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eve.sso.client_id' => 'test-client-id',
            'eve.sso.client_secret' => 'test-secret',
        ]);
    }

    public function test_returns_stored_token_while_still_valid(): void
    {
        Http::fake();

        $character = Character::factory()->create([
            'access_token' => 'still-valid',
            'access_token_expires_at' => now()->addMinutes(10),
        ]);

        $token = $this->app->make(AccessTokenProvider::class)->tokenFor($character);

        $this->assertSame('still-valid', $token);
        Http::assertNothingSent();
    }

    public function test_refreshes_expired_token_and_persists_rotated_refresh_token(): void
    {
        Http::fake([
            'login.eveonline.com/v2/oauth/token' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'rotated-refresh',
                'expires_in' => 1199,
            ]),
        ]);

        $character = Character::factory()->withExpiredAccessToken()->create([
            'refresh_token' => 'old-refresh',
        ]);

        $token = $this->app->make(AccessTokenProvider::class)->tokenFor($character);

        $this->assertSame('new-access', $token);

        $character->refresh();
        $this->assertSame('new-access', $character->access_token);
        $this->assertSame('rotated-refresh', $character->refresh_token);
        $this->assertTrue($character->access_token_expires_at->isFuture());

        Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'old-refresh');
    }
}
