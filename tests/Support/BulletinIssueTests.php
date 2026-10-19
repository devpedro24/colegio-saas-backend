<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Academico\Grado;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Services\GradebookService;
use App\Services\AcademicPromotionService;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

trait BulletinIssueTests
{
    public function test_individual_bulletin_requires_closed_period_approved_observation_and_complete_grades(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.emisiones.preparar', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('indicadores.base.gestionar', 'web'));
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('bulletin-issues')->plainTextToken);
        $this->postJson('http://localhost/api/indicadores?opaque=1', [
            'ano_token' => OpaqueUrlToken::for('ano-lectivo', $this->year->id),
            'grado_token' => OpaqueUrlToken::for('grado', $this->group->grado_id),
            'materia_token' => OpaqueUrlToken::for('materia', $this->subject->id),
            'texto' => 'Describe las ideas principales del período.', 'orden' => 1,
        ])->assertCreated();
        $url = 'http://localhost/api/boletines/emisiones/'.OpaqueUrlToken::for('matricula', $enrollment->id).'/preparar?opaque=1';
        $selection = ['contexto' => 'periodo', 'periodo_token' => OpaqueUrlToken::for('periodo', $period->id)];
        $this->postJson($url, $selection)->assertUnprocessable();
        $period->update(['estado' => 'cerrado']);
        $this->postJson($url, $selection)->assertUnprocessable();
        $period->update(['estado' => 'abierto']);
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.observaciones.gestionar', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.observaciones.aprobar', 'web'));
        app('auth')->forgetGuards();
        $observation = 'http://localhost/api/boletines/observaciones/'.OpaqueUrlToken::for('matricula', $enrollment->id);
        $this->putJson($observation.'?opaque=1', [...$selection, 'contenido' => 'Buen progreso durante el período.', 'version' => 0])
            ->assertOk();
        $this->postJson($observation.'/aprobar?opaque=1', [...$selection, 'version' => 1])->assertOk();
        $period->update(['estado' => 'cerrado']);
        $this->postJson($url, $selection)->assertUnprocessable();
        $this->assertDatabaseCount('boletin_emisiones', 0);
        $period->update(['estado' => 'abierto']);
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '4.5', 'version' => 0,
        ]]);
        $period->update(['estado' => 'cerrado']);
        $this->postJson($url, $selection)->assertOk()->assertJsonPath('data.estado', 'preparado');
        $this->assertDatabaseCount('boletin_emisiones', 1);
        $snapshot = json_decode(DB::table('boletin_emisiones')->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Describe las ideas principales del período.', $snapshot['indicadores'][0]['texto']);
        $this->assertArrayNotHasKey('valoracion', $snapshot['indicadores'][0]);
    }

    public function test_bulletin_pdf_is_private_until_publication_and_archived_with_integrity(): void
    {
        Storage::fake('tenant');
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        foreach (['ver', 'preparar', 'aprobar', 'publicar'] as $action) {
            $this->rector->givePermissionTo(Permission::findOrCreate('boletines.emisiones.'.$action, 'web'));
        }
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '4.5', 'version' => 0,
        ]]);
        DB::table('observaciones_boletin')->insert(['matricula_id' => $enrollment->id,
            'contexto' => 'periodo:'.$period->id, 'periodo_id' => $period->id,
            'contenido' => 'Aprendizaje constante.', 'estado' => 'aprobada', 'version' => 2,
            'updated_by' => $this->rector->id, 'aprobado_por' => $this->rector->id,
            'aprobado_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $period->update(['estado' => 'cerrado']);
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('bulletin-issues')->plainTextToken);
        $base = 'http://localhost/api/boletines/emisiones/';
        $enrollmentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $selection = ['contexto' => 'periodo', 'periodo_token' => OpaqueUrlToken::for('periodo', $period->id)];
        $prepared = $this->postJson($base.$enrollmentToken.'/preparar?opaque=1', $selection)
            ->assertOk()->assertJsonPath('data.estado', 'preparado')->json('data');
        $this->postJson($base.$enrollmentToken.'/preparar?opaque=1', $selection)
            ->assertJsonPath('data.token', $prepared['token']);
        $document = $base.'documentos/'.$prepared['token'];
        $this->get($document.'/pdf?opaque=1')->assertNotFound();
        $this->postJson($document.'/aprobar?opaque=1')->assertOk()->assertJsonPath('data.estado', 'aprobado');
        $file = DB::table('boletin_emisiones')->first();
        Storage::disk('tenant')->assertExists($file->pdf_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('tenant')->get($file->pdf_path));
        $this->assertSame(hash('sha256', Storage::disk('tenant')->get($file->pdf_path)), $file->pdf_sha256);
        $this->withToken($enrollment->estudiante->createToken('bulletin-student')->plainTextToken);
        app('auth')->forgetGuards();
        $this->getJson($base.$enrollmentToken.'?opaque=1')->assertOk()->assertJsonPath('total', 0);
        $this->get($document.'/pdf?opaque=1')->assertForbidden();
        $this->withToken($this->rector->createToken('bulletin-publisher')->plainTextToken);
        app('auth')->forgetGuards();
        $this->postJson($document.'/publicar?opaque=1')->assertOk()->assertJsonPath('data.estado', 'publicado');
        $this->withToken($enrollment->estudiante->createToken('bulletin-reader')->plainTextToken);
        app('auth')->forgetGuards();
        $this->getJson($base.$enrollmentToken.'?opaque=1')->assertOk()->assertJsonPath('total', 1);
        $this->get($document.'/pdf?opaque=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Storage::disk('tenant')->put($file->pdf_path, 'altered');
        $this->get($document.'/pdf?opaque=1')->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'boletin_emision', 'accion' => 'PUBLISH']);
    }

    public function test_bulletin_rejects_stale_preparation_and_keeps_new_revision_separate(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.emisiones.preparar', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.emisiones.aprobar', 'web'));
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '4', 'version' => 0,
        ]]);
        DB::table('observaciones_boletin')->insert(['matricula_id' => $enrollment->id,
            'contexto' => 'periodo:'.$period->id, 'periodo_id' => $period->id,
            'contenido' => 'Observación inicial.', 'estado' => 'aprobada', 'version' => 2,
            'updated_by' => $this->rector->id, 'aprobado_por' => $this->rector->id,
            'aprobado_en' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $period->update(['estado' => 'cerrado']);
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('bulletin-stale')->plainTextToken);
        $base = 'http://localhost/api/boletines/emisiones/';
        $path = $base.OpaqueUrlToken::for('matricula', $enrollment->id).'/preparar?opaque=1';
        $selection = ['contexto' => 'periodo', 'periodo_token' => OpaqueUrlToken::for('periodo', $period->id)];
        $first = $this->postJson($path, $selection)->assertOk()->json('data');
        DB::table('observaciones_boletin')->where('matricula_id', $enrollment->id)
            ->update(['contenido' => 'Observación corregida.', 'version' => 3, 'updated_at' => now()]);
        $this->postJson($base.'documentos/'.$first['token'].'/aprobar?opaque=1')->assertConflict();
        $second = $this->postJson($path, $selection)->assertOk()->assertJsonPath('data.revision', 2)->json('data');
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertDatabaseHas('boletin_emisiones', ['revision' => 1, 'estado' => 'descartado']);
        $this->postJson($base.'documentos/'.$first['token'].'/aprobar?opaque=1')->assertConflict();
    }

    public function test_final_bulletin_respects_optional_school_observation_policy_and_current_promotion(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.emisiones.preparar', 'web'));
        $this->year->update(['num_periodos' => 1]);
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '4.5', 'version' => 0,
        ]]);
        $promotion = app(AcademicPromotionService::class);
        $promotion->savePolicy($this->rector, $this->year, 0, [], null, 0);
        $targetGrade = Grado::create(['ano_lectivo_id' => $this->year->id,
            'nivel_id' => $this->group->grado->nivel_id, 'nombre' => 'Segundo',
            'codigo' => '02', 'estado' => 'activo']);
        $period->update(['estado' => 'cerrado']);
        $proposal = $promotion->proposal($this->year->fresh(), $enrollment);
        $this->assertSame('calculada', $proposal['estado']);
        $this->assertSame('promovido', $proposal['resultado']);
        $promotion->approve($this->rector, $this->year, $enrollment,
            $proposal['huella'], 'promovido', $targetGrade, 'Cierre académico revisado.', 0);
        $this->year->update(['estado' => 'cerrado']);
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->rector->createToken('bulletin-final')->plainTextToken);
        $url = 'http://localhost/api/boletines/emisiones/'.OpaqueUrlToken::for('matricula', $enrollment->id).'/preparar?opaque=1';
        $first = $this->postJson($url, ['contexto' => 'final'])->assertOk()
            ->assertJsonPath('data.contexto', 'final')->json('data');
        $this->assertSame(1, $first['revision']);
        DB::table('configuracion_boletines')->insertOrIgnore(['id' => 1,
            'requiere_observacion_final' => false, 'docente_edita_indicadores_grupo' => false,
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('configuracion_boletines')->where('id', 1)->update(['requiere_observacion_final' => true]);
        $this->postJson($url, ['contexto' => 'final'])->assertUnprocessable();
        $this->assertDatabaseCount('boletin_emisiones', 1);
    }
}
