<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WorkDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('auth')->prefix('test')->group(base_path('routes/work_drafts.php'));
    }

    private function payload(int $version = 0): array
    {
        return ['version' => $version, 'iv' => base64_encode(random_bytes(12)),
            'ciphertext' => base64_encode(random_bytes(100)), 'edited_at' => now()->toISOString()];
    }

    public function test_private_keys_are_stable_encrypted_and_drafts_are_scoped_to_the_owner(): void
    {
        $this->getJson('/test/work-drafts')->assertUnauthorized();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAs($a);
        $bootstrap = $this->getJson('/test/work-drafts')->assertOk()->assertJsonCount(0, 'drafts')->json();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $bootstrap['scope']);
        $this->assertSame(32, strlen(base64_decode($bootstrap['key'])));
        $storedKey = DB::table('work_draft_vaults')->where('user_id', $a->id)->value('encrypted_key');
        $this->assertNotSame($bootstrap['key'], $storedKey);
        $this->assertSame($bootstrap['key'], Crypt::decryptString($storedKey));
        $this->assertSame($bootstrap['key'], $this->getJson('/test/work-drafts')->json('key'));
        $token = str_repeat('a', 24);
        $this->putJson('/test/work-drafts/'.$token, $this->payload())->assertOk()->assertJsonPath('version', 1)->assertJsonMissingPath('user_id');
        $this->actingAs($b);
        $other = $this->getJson('/test/work-drafts')->assertOk()->assertJsonCount(0, 'drafts')->json();
        $this->assertNotSame($bootstrap['scope'], $other['scope']);
        $this->assertNotSame($bootstrap['key'], $other['key']);
        $this->putJson('/test/work-drafts/'.$token, $this->payload(1))->assertNotFound();
        $this->postJson('/test/work-drafts/'.$token.'/discard', ['version' => 1])->assertNotFound();
        $this->putJson('/test/work-drafts/123', $this->payload())->assertNotFound();
    }

    public function test_versions_and_tombstones_prevent_stale_tabs_from_overwriting_or_resurrecting(): void
    {
        $this->actingAs(User::factory()->create());
        $token = str_repeat('b', 24);
        $payload = $this->payload();
        $this->putJson('/test/work-drafts/'.$token, $payload)->assertOk();
        $this->putJson('/test/work-drafts/'.$token, $payload)->assertOk()->assertJsonPath('version', 1);
        $this->putJson('/test/work-drafts/'.$token, $this->payload())->assertStatus(409);
        $this->postJson('/test/work-drafts/'.$token.'/discard', ['version' => 0])->assertStatus(409);
        $this->postJson('/test/work-drafts/'.$token.'/discard', ['version' => 1])->assertOk()->assertJsonPath('discarded', true)->assertJsonPath('ciphertext', null);
        $this->putJson('/test/work-drafts/'.$token, $this->payload(2))->assertStatus(410);
        $this->postJson('/test/work-drafts/'.$token.'/discard', ['version' => 1])->assertOk();
    }

    public function test_retention_is_measured_from_edit_not_upload_or_read(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->startOfSecond());
        $token = str_repeat('c', 24);
        $payload = $this->payload();
        $expected = now()->addDay()->toISOString();
        $this->travel(3)->hours();
        $this->putJson('/test/work-drafts/'.$token, $payload)->assertOk()->assertJsonPath('expires_at', $expected);
        $this->travel(20)->hours();
        $this->getJson('/test/work-drafts')->assertJsonPath('drafts.0.expires_at', $expected);
        $this->travel(2)->hours();
        $this->getJson('/test/work-drafts')->assertJsonCount(0, 'drafts');
        $this->putJson('/test/work-drafts/'.str_repeat('d', 24), $payload)->assertStatus(410);
        $this->artisan('drafts:prune')->assertSuccessful();
        $this->assertDatabaseCount('work_drafts', 0);
    }

    public function test_invalid_ciphertext_and_future_dates_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $url = '/test/work-drafts/'.str_repeat('e', 24);
        $this->putJson($url, [...$this->payload(), 'iv' => 'plaintext'])->assertUnprocessable();
        $this->putJson($url, [...$this->payload(), 'ciphertext' => str_repeat('a', 700001)])->assertUnprocessable();
        $this->putJson($url, [...$this->payload(), 'edited_at' => now()->addDay()->toISOString()])->assertUnprocessable();
        $this->assertDatabaseCount('work_drafts', 0);
    }

    public function test_draft_vaults_do_not_cross_database_boundaries_even_for_the_same_user_key(): void
    {
        $this->actingAs(User::factory()->create());
        $first = $this->getJson('/test/work-drafts')->assertOk()->json();
        $token = str_repeat('t', 24);
        $this->putJson('/test/work-drafts/'.$token, $this->payload())->assertOk();
        $original = DB::getDefaultConnection();
        config(['database.connections.draft_isolation_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        try {
            DB::setDefaultConnection('draft_isolation_test');
            $this->artisan('migrate', ['--database' => 'draft_isolation_test', '--path' => 'database/migrations/tenant/2026_10_25_000001_create_work_drafts.php', '--force' => true])->assertSuccessful();
            $second = $this->getJson('/test/work-drafts')->assertOk()->assertJsonCount(0, 'drafts')->json();
            $this->assertNotSame($first['key'], $second['key']);
            $this->assertNotSame($first['scope'], $second['scope']);
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('draft_isolation_test');
        }
        $this->getJson('/test/work-drafts')->assertJsonCount(1, 'drafts')->assertJsonPath('key', $first['key']);
    }
}
