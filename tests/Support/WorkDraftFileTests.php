<?php

namespace Tests\Support;

use App\Http\Controllers\Api\WorkDraftFileController;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\Aula;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AulaSeccion;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\FileScanner;
use App\Support\Storage\StorageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

trait WorkDraftFileTests
{
    private function prepareWorkDraftSection(): AulaSeccion
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        Storage::fake('tenant');
        config(['storage.disk' => 'tenant']);
        Plan::updateOrCreate(['key' => 'estandar'], ['name' => 'Estándar', 'features' => ['aula']]);
        $this->school->update(['plan' => 'estandar']);
        foreach (['aula.ver_asignadas', 'aula.recursos.gestionar'] as $permission) {
            $this->teacher->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Actual', 'orden' => 1,
            'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-12-01', 'estado' => 'abierto']);
        $aula = Aula::create(['ano_lectivo_id' => $this->year->id, 'grupo_id' => $this->group->id,
            'materia_id' => $this->subject->id]);
        $this->withHeader('X-Tenant', $this->school->id)->withToken($this->teacher->createToken('web')->plainTextToken);

        return AulaSeccion::create(['aula_id' => $aula->id, 'periodo_id' => $period->id,
            'titulo' => 'Borradores privados', 'visible_estudiantes' => true]);
    }

    public function test_work_draft_files_are_scanned_private_and_expire_without_publishing(): void
    {
        $section = $this->prepareWorkDraftSection();
        $scanner = \Mockery::mock(FileScanner::class);
        $scanner->shouldReceive('scan')->once();
        $this->app->instance(FileScanner::class, $scanner);
        $token = str_repeat('q', 24);
        $url = 'http://localhost/api/work-draft-files/'.OpaqueUrlToken::for('aula-seccion', $section->id).'/'.$token;
        $payload = ['archivo' => UploadedFile::fake()->create('actividad.pdf', 10, 'application/pdf')];
        $this->post($url, $payload)->assertCreated()->assertExactJson(['token' => $token]);
        $this->post($url, $payload)->assertOk();
        $download = 'http://localhost/api/work-draft-files/'.$token;
        $this->getJson($download)->assertOk()->assertJsonPath('name', 'actividad.pdf')->assertJsonPath('type', 'application/pdf');
        $this->assertSame(0, DB::table('aula_recursos')->count());
        $this->assertSame(0, DB::table('aula_adjuntos')->count());
        $row = StoredFile::where('tenant_id', $this->school->id)->firstOrFail();
        Storage::disk('tenant')->assertExists($row->path);
        $this->withToken($this->rector->createToken('web')->plainTextToken);
        $this->app['auth']->forgetGuards();
        $this->getJson($download)->assertNotFound();
        DB::table('work_draft_files')->where('token', $token)->update(['expires_at' => now()->subSecond()]);
        $this->withToken($this->teacher->createToken('web')->plainTextToken);
        $this->app['auth']->forgetGuards();
        $this->getJson($download)->assertNotFound();
        WorkDraftFileController::prune();
        Storage::disk('tenant')->assertMissing($row->path);
        $this->assertSame(0, DB::table('work_draft_files')->count());
    }

    public function test_work_draft_file_scanner_failure_and_closed_period_reject_uploads(): void
    {
        $section = $this->prepareWorkDraftSection();
        $scanner = \Mockery::mock(FileScanner::class);
        $scanner->shouldReceive('scan')->once()->andThrow(new StorageException('Prueba de archivo rechazado'));
        $this->app->instance(FileScanner::class, $scanner);
        $url = 'http://localhost/api/work-draft-files/'.OpaqueUrlToken::for('aula-seccion', $section->id).'/'.str_repeat('r', 24);
        $this->postJson($url, ['archivo' => UploadedFile::fake()->create('actividad.pdf', 10, 'application/pdf')])->assertUnprocessable();
        $this->assertSame(0, DB::table('work_draft_files')->count());
        $this->assertSame([], Storage::disk('tenant')->allFiles());
        $section->periodo->update(['estado' => 'cerrado']);
        DB::table('aula_politicas')->updateOrInsert(['id' => 1], ['permitir_edicion_periodos_cerrados' => false]);
        $this->postJson($url, ['archivo' => UploadedFile::fake()->create('actividad.pdf', 10, 'application/pdf')])->assertUnprocessable();
        $this->assertSame(0, DB::table('work_draft_files')->count());
    }

    public function test_work_draft_file_student_backup_does_not_submit_task_and_rechecks_deadline(): void
    {
        $section = $this->prepareWorkDraftSection();
        $student = User::create(['name' => 'Estudiante borrador', 'email' => 'draft-student@test.test',
            'password' => 'TestingPassword123', 'role' => 'estudiante', 'status' => 'active']);
        foreach (['aula.ver_propias', 'aula.entregas.enviar'] as $permission) {
            $student->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
            'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        $resource = AulaRecurso::create(['seccion_id' => $section->id, 'tipo' => 'tarea',
            'titulo' => 'Respuesta privada', 'estado' => 'publicado', 'visible_estudiantes' => true, 'disponible_hasta' => now()->addHour()]);
        $this->withToken($student->createToken('web')->plainTextToken);
        $this->app['auth']->forgetGuards();
        $token = str_repeat('s', 24);
        $url = 'http://localhost/api/work-draft-files/response/'.OpaqueUrlToken::for('aula-recurso', $resource->id).'/'.$token;
        $this->postJson($url, ['archivo' => UploadedFile::fake()->create('respuesta.pdf', 10, 'application/pdf')])->assertCreated();
        $this->getJson('http://localhost/api/work-draft-files/'.$token)->assertOk();
        $this->assertSame(0, DB::table('aula_entregas')->count());
        $this->assertSame(0, DB::table('aula_adjuntos')->count());
        $resource->update(['configuracion' => ['reenvios' => true]]);
        $submit = 'http://localhost/api/aula/recursos/'.OpaqueUrlToken::for('aula-recurso', $resource->id).'/entregas?opaque=1';
        $this->postJson($submit, ['texto' => 'Respuesta confirmada', 'version' => 0])->assertCreated()->assertJsonPath('data.version', 1);
        $this->postJson($submit, ['texto' => 'Borrador antiguo', 'version' => 0])->assertStatus(409);
        $this->assertSame('Respuesta confirmada', DB::table('aula_entregas')->value('texto'));
        $resource->update(['disponible_hasta' => now()->subMinute()]);
        $this->postJson($url, ['archivo' => UploadedFile::fake()->create('respuesta.pdf', 10, 'application/pdf')])->assertUnprocessable();
        $this->getJson('http://localhost/api/work-draft-files/'.$token)->assertOk();
    }
}
