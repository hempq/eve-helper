<?php

namespace Tests\Feature\Auth;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSsoKeys;
use Tests\TestCase;

class EveSsoLoginTest extends TestCase
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

    public function test_redirect_sends_user_to_eve_sso_with_state(): void
    {
        $response = $this->get(route('eve.login'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringStartsWith('https://login.eveonline.com/v2/oauth/authorize?', $location);
        $this->assertStringContainsString('client_id=test-client-id', $location);
        $this->assertStringContainsString('response_type=code', $location);
        $this->assertStringContainsString(urlencode('esi-skills.read_skills.v1'), $location);
        $this->assertNotNull(session('eve_sso_state'));
        $this->assertStringContainsString('state='.session('eve_sso_state'), $location);
    }

    public function test_callback_creates_character_and_logs_in(): void
    {
        $keys = FakeSsoKeys::generate();
        $jwt = $keys->issueToken();

        Http::fake([
            'login.eveonline.com/v2/oauth/token' => Http::response([
                'access_token' => $jwt,
                'refresh_token' => 'refresh-token-1',
                'expires_in' => 1199,
                'token_type' => 'Bearer',
            ]),
            'login.eveonline.com/oauth/jwks' => Http::response($keys->jwks),
        ]);

        $response = $this
            ->withSession(['eve_sso_state' => 'state-abc'])
            ->get(route('eve.callback', ['code' => 'auth-code', 'state' => 'state-abc']));

        $response->assertRedirect(route('home'));

        $character = Character::find(91234567);
        $this->assertNotNull($character);
        $this->assertSame('Test Pilot', $character->name);
        $this->assertSame('ownerhash123=', $character->owner_hash);
        $this->assertSame(['esi-skills.read_skills.v1'], $character->scopes);
        $this->assertSame($jwt, $character->access_token);
        $this->assertSame('refresh-token-1', $character->refresh_token);
        $this->assertSame(91234567, session('character_id'));

        // Tokens must not be stored in plaintext.
        $raw = $character->getRawOriginal('refresh_token');
        $this->assertNotSame('refresh-token-1', $raw);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/oauth/token')
                ? $request['grant_type'] === 'authorization_code' && $request['code'] === 'auth-code'
                : true;
        });
    }

    public function test_callback_rejects_mismatched_state(): void
    {
        $this
            ->withSession(['eve_sso_state' => 'expected'])
            ->get(route('eve.callback', ['code' => 'x', 'state' => 'forged']))
            ->assertForbidden();
    }

    public function test_callback_without_code_redirects_home_with_error(): void
    {
        $this
            ->withSession(['eve_sso_state' => 's'])
            ->get(route('eve.callback', ['state' => 's']))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error');
    }

    public function test_logout_clears_session(): void
    {
        $this
            ->withSession(['character_id' => 91234567])
            ->post(route('eve.logout'))
            ->assertRedirect(route('home'));

        $this->assertNull(session('character_id'));
    }
}
