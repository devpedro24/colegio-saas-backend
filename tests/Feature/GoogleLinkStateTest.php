<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GoogleLinkStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_link_requires_initiating_browser_and_state_is_single_use(): void
    {
        config()->set('services.google.client_id', 'google-test-client');
        config()->set('services.google.client_secret', 'google-test-secret');

        $user = User::create([
            'name' => 'Admin',
            'email' => 'google-link-state@example.test',
            'password' => 'Password1!',
            'role' => 'superadmin',
            'status' => User::STATUS_ACTIVE,
        ]);

        $token = $user->createToken('platform')->plainTextToken;
        $start = $this->withToken($token)->postJson('/api/account/google/connect')->assertOk();
        parse_str((string) parse_url($start->json('url'), PHP_URL_QUERY), $query);
        $state = (string) ($query['state'] ?? '');
        $this->assertNotSame('', $state);

        $stateCookie = collect($start->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'colegio_google_link_state');
        $this->assertNotNull($stateCookie);
        $this->assertTrue($stateCookie->isHttpOnly());
        $payload = json_decode(Crypt::decryptString($state), true);
        $this->assertSame($payload['nonce'], $stateCookie->getValue());
        $this->assertTrue(Cache::has('google-link-state:'.hash('sha256', $payload['nonce'])));

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'sub' => 'google-account-123', 'email' => 'admin@gmail.com',
            ]),
        ]);

        $callback = '/api/account/google/callback?'.http_build_query(['state' => $state, 'code' => 'code-1']);

        $this->withUnencryptedCookie('colegio_google_link_state', 'another-browser')
            ->get($callback)->assertRedirectContains('google=error');
        $this->assertNull($user->fresh()->google_id);
        Http::assertNothingSent();
        $this->assertTrue(Cache::has('google-link-state:'.hash('sha256', $payload['nonce'])));

        $success = $this->withUnencryptedCookie('colegio_google_link_state', $stateCookie->getValue())
            ->get($callback);
        Http::assertSentCount(2);
        $success->assertRedirectContains('google=linked');
        $this->assertSame('google-account-123', $user->fresh()->google_id);

        $this->withUnencryptedCookie('colegio_google_link_state', $stateCookie->getValue())
            ->get($callback)->assertRedirectContains('google=error');
        Http::assertSentCount(2);
    }
}
