<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Materia;
use App\Models\Academico\Nivel;
use App\Models\User;
use App\Services\AsignacionHorarioService;
use App\Services\GroupSubjectScope;
use App\Services\HorarioService;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Isolated tenant fixture only; no real school writes. */
trait AcademicWorkflowTests
{
    private function workflowFixture(): string
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.configurar', 'web'));
        $this->withHeader('X-Tenant', $this->school->id)->withToken($this->rector->createToken('web')->plainTextToken);

        return 'http://localhost/api/siee/'.OpaqueUrlToken::for('ano-lectivo', $this->year->id).'/curriculo/masivo';
    }

    private function curriculumItem(?Grado $grade = null, ?Materia $subject = null, ?string $weight = null): array
    {
        return ['grado_token' => OpaqueUrlToken::for('grado', ($grade ?? $this->group->grado)->id),
            'materia_token' => OpaqueUrlToken::for('materia', ($subject ?? $this->subject)->id), 'peso_area' => $weight];
    }

    public function test_bulk_curriculum_is_idempotent_and_derives_areas_without_simple_weights(): void
    {
        DB::table('materias_curriculares')->where('ano_lectivo_id', $this->year->id)->delete();
        $this->assertSame('curriculum', \App\Support\Realtime\RealtimeChanges::resourceForPath('api/siee/token/curriculo/masivo'));
        $this->assertSame('academic-config', \App\Support\Realtime\RealtimeChanges::resourceForPath('api/siee/token'));
        $path = $this->workflowFixture();
        $second = $this->group->grado->replicate();
        $second->fill(['nombre' => 'Segundo', 'codigo' => '02'])->save();
        $items = [$this->curriculumItem(weight: '25'), $this->curriculumItem($second)];
        $this->putJson($path, ['items' => $items])->assertOk()->assertJsonPath('data.guardados', 2);
        $rows = DB::table('materias_curriculares')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row->peso_area);
            $this->assertEquals($this->subject->area_id, $row->area_id);
        }
        $ids = $rows->pluck('id')->all();
        $this->putJson($path, ['items' => $items])->assertOk()->assertJsonPath('data.guardados', 0);
        $this->assertSame($ids, DB::table('materias_curriculares')->pluck('id')->all());
        $this->assertSame(2, DB::table('audit_logs')->where('recurso', 'materia_curricular')->count());
    }

    public function test_bulk_curriculum_accepts_weighted_decimals_and_rolls_back_invalid_batches(): void
    {
        $path = $this->workflowFixture();
        $this->year->update(['siee' => ['usar_areas' => true, 'modo_area' => 'WEIGHTED_AVERAGE']]);
        $this->putJson($path, ['items' => [$this->curriculumItem()]])->assertUnprocessable();
        $item = $this->curriculumItem(weight: '20,5');
        $this->putJson($path, ['items' => [$item]])->assertOk()->assertJsonPath('data.guardados', 1);
        $this->assertEquals(20.5, DB::table('materias_curriculares')->value('peso_area'));
        $auditCount = DB::table('audit_logs')->where('recurso', 'materia_curricular')->count();
        $changed = [...$item, 'peso_area' => '70'];
        $invalid = [...$item, 'materia_token' => str_repeat('z', 24)];
        $this->putJson($path, ['items' => [$changed, $invalid]])->assertNotFound();
        $this->putJson($path, ['items' => [$changed, $changed]])->assertUnprocessable();
        $this->putJson($path, ['items' => [[...$item, 'area_id' => 1]]])->assertUnprocessable();
        $this->assertEquals(20.5, DB::table('materias_curriculares')->value('peso_area'));
        $this->assertSame($auditCount, DB::table('audit_logs')->where('recurso', 'materia_curricular')->count());
    }

    public function test_bulk_curriculum_checks_levels_years_permissions_and_closed_years(): void
    {
        DB::table('materias_curriculares')->where('ano_lectivo_id', $this->year->id)->delete();
        $path = $this->workflowFixture();
        $level = Nivel::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Preescolar', 'nivel_educativo' => 'preescolar', 'estado' => 'activo']);
        $subject = $this->subject->replicate();
        $subject->fill(['nombre' => 'Lenguaje inicial', 'nivel_id' => $level->id])->save();
        $this->putJson($path, ['items' => [$this->curriculumItem(), $this->curriculumItem(subject: $subject)]])->assertUnprocessable();
        $this->assertSame(0, DB::table('materias_curriculares')->count());
        $otherYear = AnoLectivo::create(['nombre' => '2027', 'fecha_inicio' => '2027-01-01', 'fecha_fin' => '2027-12-31', 'num_periodos' => 4, 'tipo_calendario' => 'A']);
        $subject->update(['ano_lectivo_id' => $otherYear->id]);
        $this->putJson($path, ['items' => [$this->curriculumItem(subject: $subject)]])->assertNotFound();
        $this->year->update(['estado' => 'cerrado']);
        $this->putJson($path, ['items' => [$this->curriculumItem()]])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->teacher->createToken('web')->plainTextToken);
        $this->putJson($path, ['items' => [$this->curriculumItem()]])->assertForbidden();
    }

    public function test_group_subject_picker_and_writes_require_grade_curriculum_including_all_level_subjects(): void
    {
        $path = $this->workflowFixture();
        $primaryLevel = $this->group->grado->nivel_id;
        $secondGrade = $this->group->grado->replicate();
        $secondGrade->fill(['nombre' => 'Segundo', 'codigo' => '02'])->save();
        $secondGroup = $this->group->replicate();
        $secondGroup->fill(['grado_id' => $secondGrade->id, 'nombre' => 'B'])->save();
        $preschoolLevel = Nivel::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Preescolar', 'nivel_educativo' => 'preescolar', 'estado' => 'activo']);
        $preschoolGrade = Grado::create(['ano_lectivo_id' => $this->year->id, 'nivel_id' => $preschoolLevel->id,
            'nombre' => 'Prejardín', 'codigo' => 'PJ', 'estado' => 'activo']);
        $preschoolGroup = $this->group->replicate();
        $preschoolGroup->fill(['grado_id' => $preschoolGrade->id, 'nombre' => 'A'])->save();
        $primarySubject = $this->subject->replicate();
        $primarySubject->fill(['nombre' => 'Matemáticas', 'nivel_id' => $primaryLevel])->save();
        $preschoolSubject = $this->subject->replicate();
        $preschoolSubject->fill(['nombre' => 'Dimensión cognitiva', 'nivel_id' => $preschoolLevel->id])->save();
        // Biología has no nivel_id, like Educación Física/Artística, but it is not
        // offered to either grade until explicitly included in its curriculum.
        $this->putJson($path, ['items' => [
            $this->curriculumItem($secondGrade, $primarySubject),
            $this->curriculumItem($preschoolGrade, $preschoolSubject),
        ]])->assertOk();
        $options = static fn (Grupo $group) => 'http://localhost/api/catalogos-academicos?opaque=1&tipo=materias&ano_lectivo_token='.
            OpaqueUrlToken::for('ano-lectivo', $group->ano_lectivo_id).'&compatible_grupo_token='.OpaqueUrlToken::for('grupo', $group->id);
        foreach ([$secondGroup, $preschoolGroup] as $group) {
            $this->getJson($options($group))->assertOk()->assertJsonCount(1, 'data');
            $this->getJson($options($group).'&selected_token='.OpaqueUrlToken::for('materia', $this->subject->id))
                ->assertUnprocessable();
            try {
                app(AsignacionHorarioService::class)->guardar($group, $this->subject, null, $this->rector);
                $this->fail('A general subject must first be added to the grade curriculum.');
            } catch (HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        }
        $this->putJson($path, ['items' => [
            $this->curriculumItem($secondGrade, $this->subject),
            $this->curriculumItem($preschoolGrade, $this->subject),
        ]])->assertOk();
        foreach ([[$secondGroup, $primarySubject, $preschoolSubject], [$preschoolGroup, $preschoolSubject, $primarySubject]] as [$group, $own, $other]) {
            $response = $this->getJson($options($group))->assertOk()->assertJsonCount(2, 'data')
                ->assertJsonMissingPath('data.0.id');
            $this->assertEqualsCanonicalizing(
                [OpaqueUrlToken::for('materia', $own->id), OpaqueUrlToken::for('materia', $this->subject->id)],
                array_column($response->json('data'), 'url_token'));
            $this->getJson($options($group).'&selected_token='.OpaqueUrlToken::for('materia', $other->id))
                ->assertUnprocessable();
            $this->assertSame(2, GroupSubjectScope::apply(Materia::query(), $group)->count());
            app(AsignacionHorarioService::class)->guardar($group, $this->subject, null, $this->rector);
            app(HorarioService::class)->guardar(['grupo_id' => $group->id, 'materia_id' => $own->id,
                'dia' => 'lunes', 'hora_inicio' => '07:00', 'hora_fin' => '07:45'], $this->rector);
            try {
                app(AsignacionHorarioService::class)->guardar($group, $other, null, $this->rector);
                $this->fail('A subject from another educational level must not be assigned.');
            }
            catch (HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
            try {
                app(HorarioService::class)->guardar(['grupo_id' => $group->id, 'materia_id' => $other->id,
                    'dia' => 'martes', 'hora_inicio' => '07:00', 'hora_fin' => '07:45'], $this->rector);
                $this->fail('A subject from another educational level must not be scheduled.');
            } catch (HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        }
        $this->assertDatabaseMissing('asignaciones_docentes', ['grupo_id' => $preschoolGroup->id, 'materia_id' => $primarySubject->id]);
        $this->assertDatabaseMissing('asignaciones_docentes', ['grupo_id' => $secondGroup->id, 'materia_id' => $preschoolSubject->id]);
    }

    public function test_teacher_options_do_not_expose_an_unassigned_group(): void
    {
        $this->workflowFixture();
        $other = $this->group->replicate();
        $other->fill(['nombre' => 'B'])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->teacher->createToken('web')->plainTextToken);
        $this->getJson('http://localhost/api/catalogos-academicos?opaque=1&tipo=materias&ano_lectivo_token='.
            OpaqueUrlToken::for('ano-lectivo', $this->year->id).'&compatible_grupo_token='.OpaqueUrlToken::for('grupo', $other->id))
            ->assertNotFound();
    }

    public function test_admissions_permission_does_not_grant_gradebook_access_and_teacher_cannot_manage_enrollment(): void
    {
        $this->workflowFixture();
        $this->gradeFixture();
        $delegate = User::create(['name' => 'Admisiones', 'email' => 'admissions@example.test', 'password' => 'IsolatedPassword123', 'status' => 'active']);
        $delegate->givePermissionTo(Permission::findOrCreate('academico.matriculas.gestionar', 'web'));
        $this->app['auth']->forgetGuards();
        $this->withToken($delegate->createToken('web')->plainTextToken);
        $base = 'http://localhost/api/evaluacion/catalogo?opaque=1&ano_lectivo_token='.OpaqueUrlToken::for('ano-lectivo', $this->year->id);
        $this->getJson($base.'&vista=matriculas')->assertOk()->assertJsonPath('data.can_manage_enrollments', true)
            ->assertJsonPath('data.can_view_reports', false)->assertJsonCount(1, 'data.matriculas')
            ->assertJsonCount(1, 'data.grupos')->assertJsonCount(0, 'data.materias')->assertJsonCount(0, 'data.asignaciones');
        $this->getJson($base.'&vista=planillas')->assertForbidden();
        $this->getJson($base.'&vista=boletines')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->teacher->createToken('web')->plainTextToken);
        $this->getJson($base.'&vista=matriculas')->assertForbidden();
        $this->getJson($base.'&vista=planillas')->assertOk()->assertJsonCount(1, 'data.asignaciones')
            ->assertJsonCount(0, 'data.matriculas')->assertJsonCount(0, 'data.estudiantes_disponibles');
        // The exceptional approval workflow is explicitly deferred; rector rights remain intact.
        $this->assertTrue($this->rector->can('notas.editar_no_dicta'));
    }
}
