<?php

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Services\BulletinObservationCache;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

trait BulletinObservationBatchTests
{
    public function test_group_observations_batch_cache_refreshes_after_save_and_rechecks_locks_and_access(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $enrollment] = $this->gradeFixture();
        for ($i = 1; $i < 25; $i++) {
            $student = User::create(['name' => 'Estudiante '.$i, 'email' => 'batch-'.$i.'@test.test',
                'password' => 'TestPassword123!', 'role' => 'estudiante', 'status' => 'active']);
            Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
                'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        }
        $this->teacher->givePermissionTo(Permission::findOrCreate('boletines.observaciones.gestionar', 'web'));
        $this->withHeader('X-Tenant', $this->school->id)->actingAs($this->teacher, 'web');
        $group = OpaqueUrlToken::for('grupo', $this->group->id);
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $url = "http://localhost/api/boletines/observaciones/grupo?opaque=1&grupo_token={$group}&periodo_token={$periodToken}";
        $write = 'http://localhost/api/boletines/observaciones/'.OpaqueUrlToken::for('matricula', $enrollment->id).'?opaque=1';
        $payload = ['contexto' => 'periodo', 'periodo_token' => $periodToken, 'contenido' => 'Progreso inicial.', 'version' => 0];
        $this->putJson($write, $payload)->assertOk();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk()->assertJsonCount(25, 'data.matriculas')
            ->assertJsonPath('data.puede_editar', true)->assertJsonPath('data.matriculas.0.observacion.contenido', 'Progreso inicial.')
            ->assertDontSee('"id":', false)->assertDontSee('"matricula_id":', false);
        $this->assertSame(1, $this->observationContentReads());
        DB::flushQueryLog();
        $this->getJson($url)->assertOk()->assertJsonCount(25, 'data.matriculas');
        $this->assertSame(0, $this->observationContentReads(), 'Repeated reads must reuse the private server cache.');
        $this->putJson($write, [...$payload, 'contenido' => 'Observación actualizada.', 'version' => 1])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.matriculas.0.observacion.contenido', 'Observación actualizada.');
        $period->update(['estado' => Periodo::ESTADO_CERRADO]);
        DB::flushQueryLog();
        $this->getJson($url)->assertOk()->assertJsonPath('data.puede_editar', false);
        $this->assertSame(0, $this->observationContentReads(), 'Locks are fresh even when content is cached.');
        $this->assignment->update(['docente_id' => $this->rector->id]);
        $this->getJson($url)->assertForbidden();
        $this->assignment->update(['docente_id' => $this->teacher->id]);
        $this->teacher->revokePermissionTo('boletines.observaciones.gestionar');
        $this->getJson($url)->assertForbidden();
        DB::disableQueryLog();
    }

    public function test_observation_batch_rejects_foreign_selectors_and_server_cache_is_tenant_scoped(): void
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        [$period, $enrollment] = $this->gradeFixture();
        $this->teacher->givePermissionTo(Permission::findOrCreate('boletines.observaciones.gestionar', 'web'));
        $this->withHeader('X-Tenant', $this->school->id)->actingAs($this->teacher, 'web');
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $group = OpaqueUrlToken::for('grupo', $this->group->id);
        $url = 'http://localhost/api/boletines/observaciones/grupo?opaque=1';
        $this->getJson($url.'&grupo_token='.str_repeat('Z', 24).'&periodo_token='.$periodToken)->assertNotFound();
        $this->getJson($url.'&grupo_token='.$group.'&periodo_token='.$group)->assertNotFound();
        $this->getJson($url.'&grupo_id='.$this->group->id.'&periodo_token='.$periodToken)->assertUnprocessable();
        // Same database/IDs deliberately, different tenant key: a cache hit would cross tenants.
        $cache = app(BulletinObservationCache::class);
        $cache->rows($this->group->id, $period->id, [$enrollment->id]);
        $original = tenant()->id;
        try {
            tenant()->id = 'other-cache-tenant';
            DB::enableQueryLog();
            DB::flushQueryLog();
            $cache->rows($this->group->id, $period->id, [$enrollment->id]);
            $this->assertSame(1, $this->observationContentReads());
        } finally {
            tenant()->id = $original;
            DB::disableQueryLog();
        }
    }

    private function observationContentReads(): int
    {
        return count(array_filter(DB::getQueryLog(), fn ($query) => str_starts_with(strtolower($query['query']), 'select') && str_contains($query['query'], 'from "observaciones_boletin"')));
    }
}
