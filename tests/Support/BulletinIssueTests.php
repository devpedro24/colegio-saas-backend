<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\BoletinEmision;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\User;
use App\Services\AcademicPromotionService;
use App\Services\BulletinArchive;
use App\Services\GradebookService;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\StorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait BulletinIssueTests
{
    public function test_teacher_reads_full_bulletin_only_for_assigned_groups_without_issue_permissions(): void
    {
        [, $enrollment] = $this->gradeFixture();
        $otherGroup = Grupo::create(['ano_lectivo_id' => $this->year->id,
            'grado_id' => $this->group->grado_id, 'nombre' => 'B', 'estado' => 'activo']);
        $otherStudent = User::create(['name' => 'Otro estudiante', 'email' => 'other-report@test.test',
            'password' => 'StudentPassword123', 'role' => 'estudiante', 'status' => 'active']);
        $other = Matricula::create(['estudiante_id' => $otherStudent->id, 'grupo_id' => $otherGroup->id,
            'ano_lectivo_id' => $this->year->id, 'estado' => 'retirada']);
        // La consulta del boletín no debe depender del permiso de escribir calificaciones.
        Role::findByName('docente', 'web')
            ->revokePermissionTo('notas.registrar_materia_asignada');
        Role::findByName('docente', 'web')
            ->revokePermissionTo('boletines.emisiones.preparar');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->teacher->createToken('teacher-report')->plainTextToken);
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $ownToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $otherToken = OpaqueUrlToken::for('matricula', $other->id);
        $catalog = 'http://localhost/api/evaluacion/catalogo?opaque=1&vista=boletines&ano_lectivo_token='.$yearToken;
        $this->getJson($catalog)->assertOk()->assertJsonPath('data.can_view_reports', true)
            ->assertJsonCount(1, 'data.matriculas')->assertJsonPath('data.matriculas.0.url_token', $ownToken)
            ->assertJsonPath('data.estados_disponibles.0', 'activa');
        $this->getJson('http://localhost/api/evaluacion/boletines/'.$ownToken.'?opaque=1')->assertOk();
        $this->getJson('http://localhost/api/evaluacion/boletines/'.$otherToken.'?opaque=1')->assertForbidden();
        $this->getJson('http://localhost/api/boletines/emisiones/'.$otherToken.'?opaque=1')->assertForbidden();
        $this->postJson('http://localhost/api/boletines/emisiones/'.$ownToken.'/preparar?opaque=1',
            ['contexto' => 'final'])->assertForbidden();

        $secondAssignment = AsignacionDocente::create(['ano_lectivo_id' => $this->year->id,
            'grupo_id' => $otherGroup->id, 'materia_id' => $this->subject->id,
            'docente_id' => $this->teacher->id]);
        $this->getJson($catalog)->assertOk()->assertJsonCount(2, 'data.matriculas')
            ->assertJsonCount(2, 'data.grupos')->assertJsonCount(2, 'data.estados_disponibles');
        $this->getJson('http://localhost/api/evaluacion/boletines/'.$otherToken.'?opaque=1')->assertOk();
        $secondAssignment->delete();
        $this->getJson('http://localhost/api/evaluacion/boletines/'.$otherToken.'?opaque=1')->assertForbidden();
        Role::findByName('docente', 'web')
            ->revokePermissionTo('boletines.grupo.ver');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->getJson('http://localhost/api/evaluacion/boletines/'.$ownToken.'?opaque=1')->assertForbidden();
    }

    public function test_group_director_reads_own_group_bulletin_without_a_subject_assignment(): void
    {
        $this->gradeFixture();
        $directedGroup = Grupo::create(['ano_lectivo_id' => $this->year->id,
            'grado_id' => $this->group->grado_id, 'nombre' => 'Dirigido', 'estado' => 'activo']);
        $student = User::create(['name' => 'Estudiante dirigido', 'email' => 'directed-report@test.test',
            'password' => 'StudentPassword123', 'role' => 'estudiante', 'status' => 'active']);
        $enrollment = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $directedGroup->id,
            'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        DB::table('directores_grupo')->insert(['grupo_id' => $directedGroup->id,
            'docente_id' => $this->teacher->id, 'actualizado_por' => $this->rector->id,
            'version' => 1, 'asignado_en' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($this->teacher->createToken('director-report')->plainTextToken);
        $token = OpaqueUrlToken::for('matricula', $enrollment->id);
        $catalog = 'http://localhost/api/evaluacion/catalogo?opaque=1&vista=boletines&ano_lectivo_token='.
            OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $this->getJson($catalog)->assertOk()->assertJsonCount(2, 'data.matriculas')
            ->assertJsonCount(2, 'data.grupos');
        $this->getJson('http://localhost/api/catalogos-academicos?opaque=1&tipo=grupos&ano_lectivo_token='.
            OpaqueUrlToken::for('ano-lectivo', $this->year->id).'&search=Dirigido')
            ->assertOk()->assertJsonPath('data.0.url_token', OpaqueUrlToken::for('grupo', $directedGroup->id));
        $this->getJson('http://localhost/api/evaluacion/boletines/'.$token.'?opaque=1')->assertOk();

        DB::table('directores_grupo')->where('grupo_id', $directedGroup->id)->update(['docente_id' => null]);
        $this->getJson('http://localhost/api/evaluacion/boletines/'.$token.'?opaque=1')->assertForbidden();
        $this->getJson($catalog)->assertOk()->assertJsonCount(1, 'data.matriculas');
    }

    public function test_individual_bulletin_requires_saved_observation_and_complete_grades(): void
    {
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.emisiones.preparar', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('indicadores.base.gestionar', 'web'));
        $this->configureIndicators('grado');
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

    public function test_teacher_generates_pdf_in_open_period_without_approval_only_for_assigned_students(): void
    {
        Storage::fake('tenant');
        [$period, $enrollment, $activity] = $this->gradeFixture();
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        foreach (['boletines.emisiones.preparar', 'boletines.emisiones.ver', 'boletines.observaciones.gestionar'] as $permission) {
            $this->teacher->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '4.5', 'version' => 0,
        ]]);
        $this->withHeader('X-Tenant', $this->school->id)->withToken($this->teacher->createToken('teacher-emission')->plainTextToken);
        $token = OpaqueUrlToken::for('matricula', $enrollment->id);
        $selection = ['contexto' => 'periodo', 'periodo_token' => OpaqueUrlToken::for('periodo', $period->id)];
        $this->putJson('http://localhost/api/boletines/observaciones/'.$token.'?opaque=1',
            [...$selection, 'contenido' => 'Avanza en su aprendizaje.', 'version' => 0])->assertOk();
        $base = 'http://localhost/api/boletines/emisiones/';
        $issue = $this->postJson($base.$token.'/emitir?opaque=1', $selection)->assertOk()->assertJsonPath('data.pdf_disponible', true)->json('data.token');
        $this->postJson($base.$token.'/emitir?opaque=1', $selection)->assertOk()->assertJsonPath('data.token', $issue);
        $this->getJson($base.'documentos/'.$issue.'/pdf?opaque=1')->assertOk();
        $this->assertDatabaseCount('boletin_emisiones', 1);
        $originalPdf = $this->getJson($base.'documentos/'.$issue.'/pdf?opaque=1')->getContent();
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '4', 'version' => 1,
        ]]);
        $revised = $this->postJson($base.$token.'/emitir?opaque=1', $selection)->assertOk()
            ->assertJsonPath('data.revision', 2)->json('data.token');
        $this->assertNotSame($issue, $revised);
        $this->assertSame($originalPdf, $this->getJson($base.'documentos/'.$issue.'/pdf?opaque=1')->getContent());
        $files = BoletinEmision::orderBy('revision')->get();
        $this->assertNotSame($files[0]->pdf_path, $files[1]->pdf_path);
        $this->assertStringContainsString('/boletines/', $files[0]->pdf_path);
        $this->assertStringEndsWith(BulletinArchive::filename($files[0]->snapshot), $files[0]->pdf_path);
        $this->getJson($base.$token.'?opaque=1')->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.archivo', BulletinArchive::filename($files[1]->snapshot));
        Storage::fake('local');
        $legacy = StorageService::tenantFolder(tenant()).'/boletines/legacy.pdf';
        Storage::disk('tenant')->move($files[0]->pdf_path, $legacy);
        $files[0]->update(['pdf_path' => $legacy]);
        $this->artisan('storage:organize-bulletins', ['--tenant' => $this->school->slug])->assertExitCode(0);
        $this->assertSame($legacy, $files[0]->fresh()->pdf_path);
        $this->artisan('storage:organize-bulletins', ['--tenant' => $this->school->slug, '--apply' => true])->assertExitCode(0);
        Storage::disk('tenant')->assertMissing($legacy);
        $this->assertSame($originalPdf, Storage::disk('tenant')->get($files[0]->fresh()->pdf_path));
        $this->assertSame($originalPdf, Storage::disk('local')->get('bulletin-migration-backups/'.$legacy));
        $this->assertSame($originalPdf, $this->getJson($base.'documentos/'.$issue.'/pdf?opaque=1')->getContent());
        $period->update(['estado' => 'planificado']);
        $this->postJson($base.$token.'/emitir?opaque=1', $selection)->assertUnprocessable();
        // Closing the term freezes ordinary changes, not the ability to issue the frozen report.
        $period->update(['estado' => 'cerrado']);
        $this->postJson($base.$token.'/emitir?opaque=1', $selection)->assertOk()->assertJsonPath('data.token', $revised);
        $this->assignment->delete();
        $this->postJson($base.$token.'/emitir?opaque=1', $selection)->assertForbidden();
        $this->getJson($base.$token.'?opaque=1')->assertForbidden();
        $this->getJson($base.'documentos/'.$issue.'/pdf?opaque=1')->assertForbidden();
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
        $this->withToken($this->teacher->createToken('bulletin-teacher-before')->plainTextToken);
        app('auth')->forgetGuards();
        $this->getJson($base.$enrollmentToken.'?opaque=1')->assertOk()->assertJsonPath('total', 0);
        $this->get($document.'/pdf?opaque=1')->assertForbidden();
        $this->withToken($enrollment->estudiante->createToken('bulletin-student')->plainTextToken);
        app('auth')->forgetGuards();
        $this->getJson($base.$enrollmentToken.'?opaque=1')->assertOk()->assertJsonPath('total', 0);
        $this->get($document.'/pdf?opaque=1')->assertForbidden();
        $this->withToken($this->rector->createToken('bulletin-publisher')->plainTextToken);
        app('auth')->forgetGuards();
        $this->postJson($document.'/publicar?opaque=1')->assertOk()->assertJsonPath('data.estado', 'publicado');
        $this->withToken($this->teacher->createToken('bulletin-teacher-after')->plainTextToken);
        app('auth')->forgetGuards();
        $this->getJson($base.$enrollmentToken.'?opaque=1')->assertOk()->assertJsonPath('total', 1);
        $this->get($document.'/pdf?opaque=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
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
