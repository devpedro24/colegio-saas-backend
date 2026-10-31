<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Services\GradebookService;
use App\Services\IndicatorBulletinService;
use App\Services\SieeConfiguration;
use App\Support\EvaluationOpaquePresenter;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait AcademicIndicatorTests
{
    public function test_indicator_manager_reads_year_context_without_year_management_permission(): void
    {
        $role = Role::findOrCreate('secretaria', 'web');
        $role->givePermissionTo(Permission::findOrCreate('indicadores.base.gestionar', 'web'));
        $secretary = User::create(['name' => 'Secretaria', 'email' => 'indicator-secretary@test.test',
            'password' => 'SecretaryPassword123', 'role' => 'secretaria', 'status' => 'active']);
        $secretary->assignRole($role);
        $this->indicatorApi($secretary);
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $this->getJson('http://localhost/api/evaluacion/catalogo?opaque=1&vista=planillas')
            ->assertOk()->assertJsonPath('data.anos.0.url_token', $yearToken);
        $this->getJson('http://localhost/api/anos-lectivos?opaque=1')->assertForbidden();
    }

    public function test_indicator_read_permission_does_not_expose_student_catalog_without_roster_access(): void
    {
        $role = Role::findByName('docente', 'web');
        $role->revokePermissionTo('notas.registrar_materia_asignada');
        $role->revokePermissionTo('boletines.grupo.ver');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primero', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30', 'estado' => 'abierto']);
        $this->indicatorApi($this->teacher);
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $this->getJson('http://localhost/api/evaluacion/catalogo?opaque=1&vista=planillas&ano_lectivo_token='.$yearToken)
            ->assertOk()->assertJsonCount(1, 'data.asignaciones')->assertJsonCount(0, 'data.matriculas');
        $this->getJson('http://localhost/api/indicadores'.$this->indicatorContext($period))->assertOk();
        $this->getJson('http://localhost/api/catalogos-academicos?opaque=1&tipo=grupos&ano_lectivo_token='.$yearToken)
            ->assertOk();
        $this->getJson('http://localhost/api/catalogos-academicos?opaque=1&tipo=estudiantes&ano_lectivo_token='.$yearToken)
            ->assertForbidden();
    }

    private function indicatorApi(User $user): string
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        app('auth')->forgetGuards();
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($user->createToken('test-indicator')->plainTextToken);

        return 'http://localhost/api/indicadores';
    }

    private function indicatorContext(Periodo $period, ?Grupo $group = null): string
    {
        return '?opaque=1&grupo_token='.OpaqueUrlToken::for('grupo', ($group ?? $this->group)->id)
            .'&materia_token='.OpaqueUrlToken::for('materia', $this->subject->id)
            .'&periodo_token='.OpaqueUrlToken::for('periodo', $period->id);
    }

    private function indicatorCreateInput(?array $orders = null): array
    {
        return ['ano_token' => OpaqueUrlToken::for('ano-lectivo', $this->year->id),
            'grado_token' => OpaqueUrlToken::for('grado', $this->group->grado_id),
            'materia_token' => OpaqueUrlToken::for('materia', $this->subject->id),
            'periodos_orden' => $orders, 'texto' => 'Reconoce el entorno natural.', 'orden' => 1];
    }

    private function configureIndicators(string $scope, bool $teacherEdit = false, bool $confirm = false): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('indicadores.configurar', 'web'));
        $this->indicatorApi($this->rector);
        $path = 'http://localhost/api/indicadores/politica/'.OpaqueUrlToken::for('ano-lectivo', $this->year->id).'?opaque=1';
        $version = $this->getJson($path)->assertOk()->json('data.version');
        $this->putJson($path, ['version' => $version, 'alcance' => $scope,
            'docente_puede_editar' => $teacherEdit, 'confirmar_copia' => $confirm])->assertOk();
    }

    public function test_grade_scope_does_not_keep_a_hidden_teacher_edit_switch(): void
    {
        $this->configureIndicators('grado', true);
        $this->assertFalse((bool) DB::table('politicas_indicadores')
            ->where('ano_lectivo_id', $this->year->id)->value('docente_puede_editar'));
        $this->indicatorApi($this->teacher);
        $this->getJson('http://localhost/api/indicadores/politica/'
            .OpaqueUrlToken::for('ano-lectivo', $this->year->id).'?opaque=1')
            ->assertOk()->assertJsonPath('data.docente_puede_editar', false);
    }

    public function test_group_indicator_sheet_saves_safe_rich_text_and_feeds_the_bulletin(): void
    {
        $this->teacher->givePermissionTo(Permission::findOrCreate('indicadores.grupo.editar', 'web'));
        $this->configureIndicators('grupo', true);
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primero', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30', 'estado' => 'abierto']);
        $api = $this->indicatorApi($this->teacher);
        $groupToken = OpaqueUrlToken::for('grupo', $this->group->id);
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $subjectToken = OpaqueUrlToken::for('materia', $this->subject->id);
        $sheet = $api.'/tabla?opaque=1&grupo_token='.$groupToken.'&periodo_token='.$periodToken;
        $this->postJson($api.'/grupo?opaque=1', ['grupo_token' => $groupToken,
            'materia_token' => $subjectToken, 'periodo_token' => $periodToken,
            'texto' => 'Contenido anterior.'])->assertCreated();
        $this->getJson($sheet)->assertOk()->assertJsonCount(1, 'data.materias')
            ->assertJsonPath('data.materias.0.version', 0)
            ->assertJsonPath('data.materias.0.texto_html', '<p>Contenido anterior.</p>');
        $expectedHtml = '<p>Aprende <strong>sumas</strong>, <em>restas</em> y <u>formas</u>.</p>'
            .'<ul><li>Identifica objetos.</li></ul><ol><li>Explica el proceso.</li></ol>texto';
        $payload = ['grupo_token' => $groupToken, 'periodo_token' => $periodToken,
            'texto_html' => '<p>Aprende <b>sumas</b>, <i>restas</i> y <u style="color:red">formas</u>.</p>'
                .'<ul><li>Identifica objetos.</li></ul><ol><li>Explica el proceso.</li></ol>'
                .'<a href="https://example.test">texto</a><script>alert(1)</script>',
            'version' => 0];
        $this->putJson($api.'/tabla/'.$subjectToken.'?opaque=1', $payload)->assertOk()
            ->assertJsonPath('data.texto_html', $expectedHtml);
        $this->getJson($sheet)->assertOk()->assertJsonPath('data.materias.0.version', 1)
            ->assertJsonPath('data.materias.0.texto_html', $expectedHtml);
        $this->putJson($api.'/tabla/'.$subjectToken.'?opaque=1', $payload)->assertConflict();
        $this->assertSame(1, DB::table('indicadores_contenidos_grupo')->count());

        $student = User::create(['name' => 'Estudiante boletín', 'email' => 'indicator-sheet-student@test.test',
            'password' => 'StudentPassword123', 'role' => 'estudiante', 'status' => 'active']);
        $enrollment = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
            'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        $rows = app(IndicatorBulletinService::class)->forEnrollment($enrollment->load('grupo'),
            collect([$period]), collect([['materia_id' => $this->subject->id,
                'nombre' => $this->subject->nombre, 'area_id' => null]]));
        $this->assertCount(1, $rows);
        $this->assertSame($expectedHtml, $rows[0]['texto_html']);
        $this->assertStringNotContainsString('<script>', $rows[0]['texto_html']);
        $this->assertStringNotContainsString('<a ', $rows[0]['texto_html']);
        $this->rector->givePermissionTo(Permission::findOrCreate('indicadores.base.gestionar', 'web'));
        $this->indicatorApi($this->rector);
        $this->getJson($sheet)->assertOk()->assertJsonPath('data.materias.0.texto_html', $rows[0]['texto_html'])
            ->assertJsonPath('data.materias.0.puede_editar', true);
        $this->indicatorApi($this->teacher);
        $delete = $api.'/tabla/'.$subjectToken.'?opaque=1&grupo_token='.$groupToken
            .'&periodo_token='.$periodToken.'&version=';
        $this->deleteJson($delete.'0')->assertConflict();
        $this->deleteJson($delete.'1')->assertOk()->assertJsonPath('data.texto_html', '')
            ->assertJsonPath('data.version', 2);
        $this->getJson($sheet)->assertOk()->assertJsonPath('data.materias.0.texto_html', '')
            ->assertJsonPath('data.materias.0.version', 2);
        $this->assertSame([], app(IndicatorBulletinService::class)->forEnrollment($enrollment->load('grupo'),
            collect([$period]), collect([['materia_id' => $this->subject->id,
                'nombre' => $this->subject->nombre, 'area_id' => null]])));
        $this->deleteJson($delete.'1')->assertConflict();
        $this->putJson($api.'/tabla/'.$subjectToken.'?opaque=1', [...$payload,
            'texto_html' => '<p>Nuevo indicador.</p>', 'version' => 2])->assertOk()
            ->assertJsonPath('data.version', 3);
        $this->deleteJson($delete.'3')->assertOk()->assertJsonPath('data.version', 4);
        $period->update(['estado' => 'cerrado']);
        $this->getJson($sheet)->assertOk()->assertJsonPath('data.materias.0.puede_editar', false);
        $this->deleteJson($delete.'4')->assertUnprocessable();
        $period->update(['estado' => 'abierto']);
        $this->configureIndicators('grupo', false);
        $this->indicatorApi($this->teacher);
        $this->getJson($sheet)->assertOk()->assertJsonPath('data.materias.0.puede_editar', false);
        $this->putJson($api.'/tabla/'.$subjectToken.'?opaque=1', [...$payload, 'version' => 1])->assertForbidden();
        $this->deleteJson($delete.'4')->assertForbidden();
    }

    public function test_institutional_indicator_is_descriptive_and_period_scoped_without_an_assessment_scale(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('indicadores.base.gestionar', 'web'));
        $this->configureIndicators('grado');
        $first = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primero', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30', 'estado' => 'abierto']);
        $second = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Segundo', 'orden' => 2,
            'fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-08-31', 'estado' => 'abierto']);
        $api = $this->indicatorApi($this->rector);
        $created = $this->postJson($api.'?opaque=1', $this->indicatorCreateInput([1]))
            ->assertCreated()->json('data');
        $this->getJson($api.$this->indicatorContext($first))->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.indicadores.0.texto', 'Reconoce el entorno natural.')
            ->assertJsonMissingPath('data.politica')
            ->assertJsonMissingPath('data.indicadores.0.valoracion');
        $this->getJson($api.$this->indicatorContext($second))->assertOk()->assertJsonPath('data.total', 0);
        $this->putJson($api.'/'.$created['indicador_token'].'?opaque=1', [
            'version' => 1, 'texto' => 'Nueva redacción institucional.', 'orden' => 1,
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson($api.$this->indicatorContext($first))
            ->assertJsonPath('data.indicadores.0.texto', 'Reconoce el entorno natural.');
        $this->putJson($api.'/vinculos/'.$created['vinculo_token'].'?opaque=1', [
            'version' => 1, 'periodos_orden' => [1, 2], 'usar_revision_actual' => true,
        ])->assertOk();
        $this->getJson($api.$this->indicatorContext($second))
            ->assertJsonPath('data.indicadores.0.texto', 'Nueva redacción institucional.');
        $this->putJson($api.'/vinculos/'.$created['vinculo_token'].'/texto?opaque=1', [
            'version' => 2, 'texto' => 'Redacción final del grado.',
        ])->assertOk()->assertJsonPath('data.texto_vinculado', 'Redacción final del grado.');
        $this->getJson($api.$this->indicatorContext($first))
            ->assertJsonPath('data.indicadores.0.texto', 'Redacción final del grado.');
        $this->getJson($api.'/politica/'.OpaqueUrlToken::for('ano-lectivo', $this->year->id).'?opaque=1')
            ->assertOk()->assertJsonPath('data.alcance', 'grado');
        $this->assertFalse(Schema::hasTable('indicadores_configuracion'));
        $this->assertFalse(Schema::hasTable('indicadores_valoraciones'));
    }

    public function test_teacher_reads_assigned_objectives_and_edits_only_own_group_when_enabled(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('indicadores.base.gestionar', 'web'));
        $this->teacher->givePermissionTo(Permission::findOrCreate('indicadores.grupo.editar', 'web'));
        $this->configureIndicators('grado');
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primero', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30', 'estado' => 'abierto']);
        $otherGroup = Grupo::create(['ano_lectivo_id' => $this->year->id, 'grado_id' => $this->group->grado_id,
            'nombre' => 'B', 'estado' => 'activo']);
        $student = User::create(['name' => 'Estudiante de ensayo', 'email' => 'indicator-student@test.test',
            'password' => 'StudentPassword123', 'role' => 'estudiante', 'status' => 'active']);
        $enrollment = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
            'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        $api = $this->indicatorApi($this->rector);
        $created = $this->postJson($api.'?opaque=1', $this->indicatorCreateInput())
            ->assertCreated()->json('data');
        $this->configureIndicators('grupo', true, true);
        $groupBinding = $this->getJson($api.$this->indicatorContext($period))->assertOk()->json('data.indicadores.0.vinculo_token');
        $this->getJson($api.$this->indicatorContext($period, $otherGroup))->assertOk()
            ->assertJsonPath('data.indicadores.0.texto', 'Reconoce el entorno natural.');
        $variantPath = $api.'/vinculos/'.$groupBinding.'/variante?opaque=1';
        $this->indicatorApi($this->teacher);
        $this->getJson($api.$this->indicatorContext($period))->assertOk()
            ->assertJsonPath('data.indicadores.0.texto', 'Reconoce el entorno natural.');
        $this->putJson($variantPath, ['grupo_token' => OpaqueUrlToken::for('grupo', $otherGroup->id),
            'periodo_orden' => 1, 'texto' => 'Intento ajeno', 'version' => 0])->assertForbidden();
        $this->putJson($variantPath, ['grupo_token' => OpaqueUrlToken::for('grupo', $this->group->id),
            'periodo_orden' => 1, 'texto' => 'Meta del grupo A', 'version' => 0])
            ->assertOk()->assertJsonPath('data.version', 1);
        $this->getJson($api.$this->indicatorContext($period))
            ->assertJsonPath('data.indicadores.0.texto', 'Meta del grupo A');
        $this->indicatorApi($this->rector);
        $this->getJson($api.$this->indicatorContext($period, $otherGroup))
            ->assertJsonPath('data.indicadores.0.texto', 'Reconoce el entorno natural.');
        $this->indicatorApi($this->teacher);
        $this->getJson($api.$this->indicatorContext($period, $otherGroup))->assertForbidden();
        $this->putJson($api.'/vinculos/'.$created['vinculo_token'].'/valoraciones/'
            .OpaqueUrlToken::for('matricula', $enrollment->id).'?opaque=1', [])->assertNotFound();
        $scale = EscalaValorativa::create([
            'ano_lectivo_id' => $this->year->id, 'nombre' => 'Numérica', 'tipo' => 'numerica',
            'valor_min' => '0', 'valor_max' => '5', 'decimales' => 1,
        ]);
        $method = MetodoAprobacion::create([
            'ano_lectivo_id' => $this->year->id, 'calculo_nota' => 'promedio_simple',
            'nota_minima' => '3', 'ambito' => 'materia',
        ]);
        $this->year->update(['siee' => [...SieeConfiguration::DEFAULTS,
            'escala_id' => $scale->id, 'metodo_id' => $method->id]]);
        $preview = EvaluationOpaquePresenter::report(
            app(GradebookService::class)->report($enrollment->load('estudiante', 'grupo.grado')));
        $this->assertSame('Meta del grupo A', $preview['indicadores'][0]['texto']);
        $this->assertArrayNotHasKey('valoracion', $preview['indicadores'][0]);
        $this->assertArrayNotHasKey('alcanzado', $preview['indicadores'][0]);
        $period->update(['estado' => 'cerrado']);
        $this->getJson($api.$this->indicatorContext($period))->assertOk();
        $this->putJson($variantPath, ['grupo_token' => OpaqueUrlToken::for('grupo', $this->group->id),
            'periodo_orden' => 1, 'texto' => 'Cambio tardío', 'version' => 1])->assertUnprocessable();
        $this->assertSame(0, DB::table('indicadores_variantes')->where('texto', 'Cambio tardío')->count());
    }

    public function test_assigned_teacher_creates_group_only_indicator_after_school_enables_it(): void
    {
        $this->teacher->givePermissionTo(Permission::findOrCreate('indicadores.grupo.editar', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('indicadores.base.gestionar', 'web'));
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primero', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30', 'estado' => 'abierto']);
        $otherGroup = Grupo::create(['ano_lectivo_id' => $this->year->id, 'grado_id' => $this->group->grado_id,
            'nombre' => 'B', 'estado' => 'activo']);
        $input = ['grupo_token' => OpaqueUrlToken::for('grupo', $this->group->id),
            'materia_token' => OpaqueUrlToken::for('materia', $this->subject->id),
            'periodo_token' => OpaqueUrlToken::for('periodo', $period->id),
            'texto' => 'Explora y describe su entorno.'];
        $api = $this->indicatorApi($this->teacher);
        $this->postJson($api.'/grupo?opaque=1', $input)->assertForbidden();
        $this->configureIndicators('grupo', true);
        $this->indicatorApi($this->teacher);
        $this->postJson($api.'/grupo?opaque=1', [...$input,
            'grupo_token' => OpaqueUrlToken::for('grupo', $otherGroup->id)])->assertForbidden();
        $created = $this->postJson($api.'/grupo?opaque=1', $input)->assertCreated()->json('data');
        $this->getJson($api.$this->indicatorContext($period))->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonMissingPath('data.indicadores.0.fuente')
            ->assertJsonPath('data.indicadores.0.texto', $input['texto']);
        $this->putJson($api.'/vinculos/'.$created['vinculo_token'].'/variante?opaque=1', [
            'grupo_token' => OpaqueUrlToken::for('grupo', $otherGroup->id),
            'periodo_orden' => 1, 'texto' => 'Intento ajeno', 'version' => 0,
        ])->assertForbidden();
        $this->putJson($api.'/vinculos/'.$created['vinculo_token'].'/variante?opaque=1', [
            'grupo_token' => $input['grupo_token'], 'periodo_orden' => 1,
            'texto' => 'Observa y describe el entorno.', 'version' => 0,
        ])->assertOk();
        $this->getJson($api.$this->indicatorContext($period))
            ->assertJsonPath('data.indicadores.0.texto', 'Observa y describe el entorno.');

        $this->indicatorApi($this->rector);
        $this->getJson($api.$this->indicatorContext($period, $otherGroup))
            ->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson($api.'/bases?opaque=1')->assertJsonPath('meta.total', 0);
        $this->getJson($api.'/vinculos?opaque=1&ano_token='.OpaqueUrlToken::for('ano-lectivo', $this->year->id)
            .'&grado_token='.OpaqueUrlToken::for('grado', $this->group->grado_id)
            .'&materia_token='.$input['materia_token'])->assertJsonPath('meta.total', 0);
        $this->putJson($api.'/'.$created['indicador_token'].'?opaque=1', [
            'version' => 1, 'texto' => 'Cambio institucional indebido.', 'orden' => 1,
        ])->assertForbidden();
        $this->postJson($api.'/'.$created['indicador_token'].'/vinculos?opaque=1', [
            'ano_token' => OpaqueUrlToken::for('ano-lectivo', $this->year->id),
            'grado_token' => OpaqueUrlToken::for('grado', $this->group->grado_id),
            'materia_token' => $input['materia_token'], 'periodos_orden' => [1],
        ])->assertForbidden();

        $student = User::create(['name' => 'Estudiante del grupo', 'email' => 'indicator-group@test.test',
            'password' => 'StudentPassword123', 'role' => 'estudiante', 'status' => 'active']);
        $enrollment = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $this->group->id,
            'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        $subject = collect([['materia_id' => $this->subject->id, 'nombre' => $this->subject->nombre, 'area_id' => null]]);
        $rows = app(IndicatorBulletinService::class)->forEnrollment($enrollment, collect([$period]), $subject);
        $this->assertCount(1, $rows);
        $this->assertSame('Observa y describe el entorno.', $rows[0]['texto']);
        $enrollment->update(['grupo_id' => $otherGroup->id]);
        $this->assertSame([], app(IndicatorBulletinService::class)->forEnrollment($enrollment->fresh(), collect([$period]), $subject));
        $period->update(['estado' => 'cerrado']);
        $this->indicatorApi($this->teacher);
        $this->postJson($api.'/grupo?opaque=1', $input)->assertUnprocessable();
    }

    public function test_group_director_needs_a_real_subject_assignment_to_create_an_indicator(): void
    {
        $directorRole = Role::findOrCreate('director_grupo', 'web');
        $directorRole->givePermissionTo(Permission::findOrCreate('indicadores.grupo.ver', 'web'));
        $directorRole->givePermissionTo(Permission::findOrCreate('indicadores.grupo.editar', 'web'));
        $director = User::create(['name' => 'Director', 'email' => 'indicator-director@test.test',
            'password' => 'DirectorPassword123', 'role' => 'director_grupo', 'status' => 'active']);
        $director->assignRole($directorRole);
        $this->configureIndicators('grupo', true);
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primero', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30', 'estado' => 'abierto']);
        $otherGroup = Grupo::create(['ano_lectivo_id' => $this->year->id, 'grado_id' => $this->group->grado_id,
            'nombre' => 'B', 'estado' => 'activo']);
        DB::table('directores_grupo')->insert(['grupo_id' => $this->group->id, 'docente_id' => $director->id,
            'actualizado_por' => $this->rector->id, 'version' => 1, 'asignado_en' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $api = $this->indicatorApi($director);
        $this->getJson($api.'/opciones-asignadas?opaque=1')->assertOk()
            ->assertJsonCount(0, 'data.grupos');
        $input = ['grupo_token' => OpaqueUrlToken::for('grupo', $this->group->id),
            'materia_token' => OpaqueUrlToken::for('materia', $this->subject->id),
            'periodo_token' => OpaqueUrlToken::for('periodo', $period->id), 'texto' => 'Objetivo del director.'];
        $this->postJson($api.'/grupo?opaque=1', $input)->assertForbidden();
        $this->assignment->update(['docente_id' => $director->id]);
        $this->getJson($api.'/opciones-asignadas?opaque=1')->assertOk()
            ->assertJsonCount(1, 'data.grupos')->assertJsonCount(1, 'data.grupos.0.materias');
        $this->postJson($api.'/grupo?opaque=1', $input)->assertCreated();
        $this->postJson($api.'/grupo?opaque=1', [...$input,
            'grupo_token' => OpaqueUrlToken::for('grupo', $otherGroup->id)])->assertForbidden();
    }

    public function test_switching_from_group_to_grade_keeps_drafts_but_starts_with_no_active_grade_indicators(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('indicadores.base.gestionar', 'web'));
        $this->teacher->givePermissionTo(Permission::findOrCreate('indicadores.grupo.editar', 'web'));
        $period = Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primero', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-04-30', 'estado' => 'abierto']);
        $this->configureIndicators('grupo', true);
        $api = $this->indicatorApi($this->teacher);
        $this->postJson($api.'/grupo?opaque=1', [
            'grupo_token' => OpaqueUrlToken::for('grupo', $this->group->id),
            'materia_token' => OpaqueUrlToken::for('materia', $this->subject->id),
            'periodo_token' => OpaqueUrlToken::for('periodo', $period->id),
            'texto' => 'Suma y resta cantidades pequeñas.',
        ])->assertCreated();

        $this->indicatorApi($this->rector);
        $this->getJson($api.$this->indicatorContext($period))->assertOk()
            ->assertJsonPath('data.indicadores.0.texto', 'Suma y resta cantidades pequeñas.');
        $this->configureIndicators('grado', false, true);
        $this->getJson($api.$this->indicatorContext($period))->assertOk()->assertJsonPath('data.total', 0);
        $drafts = $this->getJson($api.'/borradores?opaque=1&ano_token='.OpaqueUrlToken::for('ano-lectivo', $this->year->id)
            .'&grado_token='.OpaqueUrlToken::for('grado', $this->group->grado_id)
            .'&materia_token='.OpaqueUrlToken::for('materia', $this->subject->id)
            .'&periodo_token='.OpaqueUrlToken::for('periodo', $period->id))->assertOk()->json('data');
        $this->assertSame('Suma y resta cantidades pequeñas.', $drafts[0]['texto']);
        $this->postJson($api.'?opaque=1', [...$this->indicatorCreateInput([1]), 'texto' => $drafts[0]['texto']])
            ->assertCreated();
        $this->getJson($api.$this->indicatorContext($period))->assertJsonPath('data.total', 1);
        $this->assertSame(1, DB::table('indicadores_vinculos')->whereNotNull('grupo_id')->count());
    }
}
