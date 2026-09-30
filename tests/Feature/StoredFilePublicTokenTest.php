<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StoredFile;
use App\Support\Storage\StoredFilePublicToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class StoredFilePublicTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_selector_is_persisted_indexed_and_scoped_to_owner(): void
    {
        $file = StoredFile::create([
            'tenant_id' => 'school-a', 'disk' => 'tenant', 'path' => 'school-a/document.pdf',
            'mime' => 'application/pdf', 'size' => 10, 'checksum' => str_repeat('a', 64),
            'original_name' => 'document.pdf',
        ]);
        $token = StoredFilePublicToken::for($file);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{24}\z/', $token);
        $this->assertSame($token, DB::table('stored_files')->where('id', $file->id)->value('public_token'));
        $this->assertSame($file->id, StoredFilePublicToken::findForTenant('school-a', $token)?->id);
        $this->assertNull(StoredFilePublicToken::findForTenant('school-b', $token));
        $this->assertNull(StoredFilePublicToken::findForTenant('school-a', (string) $file->id));

        $indexes = DB::select('PRAGMA index_list("stored_files")');
        $this->assertTrue(collect($indexes)->contains(fn ($index) =>
            str_contains((string) $index->name, 'public_token') && (int) $index->unique === 1));
    }

    public function test_migration_backfills_existing_hmac_selectors(): void
    {
        config(['database.connections.public_token_probe' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        $oldDefault = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection('public_token_probe');
            Schema::create('stored_files', function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id');
            });
            DB::table('stored_files')->insert(['id' => 42, 'tenant_id' => 'school-before-migration']);

            $migration = require database_path('migrations/2026_09_30_000003_index_stored_file_public_tokens.php');
            $migration->up();

            $message = json_encode(['school-before-migration', 'stored-file', '42'], JSON_THROW_ON_ERROR);
            $digest = hash_hmac('sha256', $message, (string) config('app.key'), true);
            $expected = substr(rtrim(strtr(base64_encode($digest), '+/', '-_'), '='), 0, 24);
            $this->assertSame($expected, DB::table('stored_files')->where('id', 42)->value('public_token'));
        } finally {
            DB::setDefaultConnection($oldDefault);
            DB::purge('public_token_probe');
        }
    }
}
