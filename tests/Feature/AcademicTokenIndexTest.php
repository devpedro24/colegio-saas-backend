<?php

namespace Tests\Feature;

use App\Events\ApplicationChanged;
use App\Models\Academico\AnoLectivo;
use App\Models\Tenant;
use App\Support\AcademicTokenIndex;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AcademicTokenIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.bootstrappers' => [], 'database.connections.token_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('token_test');
        Artisan::call('migrate', ['--database' => 'token_test', '--path' => 'database/migrations/tenant', '--force' => true]);
        tenancy()->initialize(new Tenant(['id' => 'token-school-a', 'status' => 'active']));
        Event::fake([ApplicationChanged::class]);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        DB::setDefaultConnection('sqlite');
        DB::purge('token_test');
        parent::tearDown();
    }

    private function year(): AnoLectivo
    {
        return AnoLectivo::create(['nombre' => '2026', 'tipo_calendario' => 'A',
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'num_periodos' => 4,
            'estado' => 'planificado']);
    }

    public function test_index_is_constant_query_count_and_keeps_resource_tenant_and_scope_checks(): void
    {
        $year = $this->year();
        $token = OpaqueUrlToken::for('ano-lectivo', $year->id);
        DB::enableQueryLog();
        $this->assertTrue($year->is(OpaqueUrlToken::find('ano-lectivo', $token, AnoLectivo::query())));
        $this->assertCount(2, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertNull(OpaqueUrlToken::find('periodo', $token, AnoLectivo::query()));
        $this->assertNull(OpaqueUrlToken::find('ano-lectivo', $token, AnoLectivo::where('estado', 'cerrado')));
        $this->assertSame([$token => $year->id], OpaqueUrlToken::ids('ano-lectivo', [$token], AnoLectivo::query()));
        $this->assertSame([], OpaqueUrlToken::ids('ano-lectivo', [$token], AnoLectivo::where('estado', 'cerrado')));
        tenancy()->initialize(new Tenant(['id' => 'token-school-b', 'status' => 'active']));
        // Even with this test sharing one physical DB, the HMAC rejects another school.
        $this->assertNull(OpaqueUrlToken::find('ano-lectivo', $token, AnoLectivo::query()));
    }

    public function test_backfill_rebuilds_raw_imports_and_deleted_records_still_obey_scopes(): void
    {
        $year = $this->year();
        $token = OpaqueUrlToken::for('ano-lectivo', $year->id);
        DB::table(AcademicTokenIndex::TABLE)->delete();
        $migration = require database_path('migrations/tenant/2026_09_30_000006_index_academic_public_tokens.php');
        $migration->down();
        $migration->up();
        $this->assertTrue($year->is(OpaqueUrlToken::find('ano-lectivo', $token, AnoLectivo::query())));
        app(AcademicTokenIndex::class)->rebuild();
        $this->assertSame(1, DB::table(AcademicTokenIndex::TABLE)->where('resource', 'ano-lectivo')->count());
        $year->delete();
        $this->assertNull(OpaqueUrlToken::find('ano-lectivo', $token, AnoLectivo::query()));
    }

    public function test_rolled_back_records_do_not_leave_index_entries(): void
    {
        DB::beginTransaction();
        $year = $this->year();
        $token = OpaqueUrlToken::for('ano-lectivo', $year->id);
        DB::rollBack();
        $this->assertFalse(DB::table(AcademicTokenIndex::TABLE)->where('token', $token)->exists());
        $this->assertNull(OpaqueUrlToken::find('ano-lectivo', $token, AnoLectivo::query()));
    }
}
