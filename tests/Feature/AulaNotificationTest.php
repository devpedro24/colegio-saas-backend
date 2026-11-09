<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Aula;
use App\Models\Academico\AulaEntrega;
use App\Models\Academico\AulaIntento;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\AulaSeccion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\EscalaOpcion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Jornada;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\Nivel;
use App\Models\Academico\Periodo;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AulaNotifications;
use App\Services\SieeConfiguration;
use App\Services\TenantProvisioner;
use App\Support\OpaqueUrlToken;
use Brick\Math\BigDecimal;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Stancl\Tenancy\DatabaseConfig;
use Tests\TestCase;

final class AulaNotificationTest extends TestCase
{
    private ?Tenant $gradebookSchool = null;

    private string $gradebookActor = 'rector';

    private function fixture(bool $visibleSection = true, bool $visual = false): array
    {
        $f = $this->gradebookFixture($visual);
        $extra = $f['school']->run(function () use ($visibleSection) {
            $assignment = AsignacionDocente::firstOrFail();
            $aula = Aula::firstOrCreate(['ano_lectivo_id' => $assignment->ano_lectivo_id,
                'grupo_id' => $assignment->grupo_id, 'materia_id' => $assignment->materia_id]);
            $section = AulaSeccion::create(['aula_id' => $aula->id, 'periodo_id' => Periodo::firstOrFail()->id,
                'titulo' => 'Sección', 'orden' => 1, 'visible_estudiantes' => $visibleSection,
                'autor_id' => User::where('role', 'rector')->value('id')]);
            $group = $aula->grupo;
            $otherGroup = Grupo::create(['ano_lectivo_id' => $aula->ano_lectivo_id, 'grado_id' => $group->grado_id,
                'nombre' => '01B', 'estado' => 'activo']);
            $oldYear = AnoLectivo::create(['nombre' => '2027', 'tipo_calendario' => 'A', 'estado' => 'cerrado',
                'fecha_inicio' => '2027-01-01', 'fecha_fin' => '2027-12-31', 'num_periodos' => 1]);
            $bearers = [];
            foreach (['peer', 'other-group', 'other-year', 'inactive', 'withdrawn', 'no-access', 'unrelated-teacher', 'admin'] as $key) {
                $role = in_array($key, ['unrelated-teacher', 'admin'], true) ? 'docente' : 'estudiante';
                $user = User::create(['name' => $key, 'email' => $key.'@aula.test', 'password' => bcrypt('Temporary1!abc'),
                    'role' => $role, 'status' => $key === 'inactive' ? 'inactive' : 'active', 'must_change_password' => false]);
                if ($key !== 'no-access') {
                    $user->assignRole($role);
                }
                if ($key === 'admin') {
                    $user->givePermissionTo('aula.ver_todas');
                }
                if ($role === 'estudiante') {
                    Matricula::create(['ano_lectivo_id' => $key === 'other-year' ? $oldYear->id : $aula->ano_lectivo_id,
                        'grupo_id' => $key === 'other-group' ? $otherGroup->id : $aula->grupo_id,
                        'estudiante_id' => $user->id, 'estado' => $key === 'withdrawn' ? 'retirada' : 'activa']);
                }
                $bearers[$key] = $user->createToken('aula-test')->plainTextToken;
            }

            return ['aulaToken' => OpaqueUrlToken::for('aula', $aula->id),
                'sectionToken' => OpaqueUrlToken::for('aula-seccion', $section->id), 'extraBearers' => $bearers];
        });

        return [...$f, ...$extra, 'bearers' => [...$f['bearers'], ...$extra['extraBearers']]];
    }

    private function input(bool $visible = true, array $extra = []): array
    {
        return [...['tipo' => 'texto', 'titulo' => 'Lectura', 'visible_estudiantes' => $visible,
            'calificable' => false, 'llevar_planilla' => false], ...$extra];
    }

    private function createResource(array $f, bool $visible = true, array $extra = []): string
    {
        $response = $this->postJson($f['base'].'/aula/secciones/'.$f['sectionToken'].'/recursos', $this->input($visible, $extra))
            ->assertCreated();
        $this->record($f, $response, 'aula.resource.store');

        return $response->json('data.token');
    }

    private function record(array $f, TestResponse $response, string $event): void
    {
        $response->assertHeader('X-Notification-Record');
        $f['school']->run(function () use ($response, $event) {
            $row = DB::table('user_notification_records')->where('url_token', $response->headers->get('X-Notification-Record'))->first();
            $this->assertSame($event, $row->event);
            if ($response->status() >= 400) {
                $this->assertNull($row->message);
                $this->assertSame('', $row->subject);
            }
            $this->assertSame($response->status() >= 400 ? 'danger' : ($event === 'aula.resource.destroy' || $event === 'aula.section.destroy' ? 'deleted' : $row->variant), $row->variant);
        });
    }

    private function news(array $f, string $role, int $total): TestResponse
    {
        $this->gradebookAuthenticate($f, $role);

        return $this->getJson($f['base'].'/notification-updates')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.total', $total);
    }

    public static function visibility(): array
    {
        return ['visible-visible' => [true, true], 'hidden-visible' => [false, true],
            'visible-hidden' => [true, false], 'hidden-hidden' => [false, false]];
    }

    #[DataProvider('visibility')]
    public function test_publication_requires_both_visible_and_recipients_are_exact(bool $section, bool $resource): void
    {
        $f = $this->fixture($section);
        $token = $this->createResource($f, $resource);
        $expected = (int) ($section && $resource);
        foreach (['estudiante', 'peer', 'docente', 'rector', 'admin'] as $role) {
            $feed = $this->news($f, $role, $expected);
            if ($expected) {
                $row = $feed->json('data.0');
                $this->assertSame(['token', 'event', 'resource_title', 'subject', 'group', 'grade', 'year', 'url', 'created_at'], array_keys($row));
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/D', $row['token']);
                $this->assertSame('aula.resource.published', $row['event']);
                $this->assertSame('/evaluacion/aula/recursos/'.$token, $row['url']);
                $this->assertSame('Primero', $row['grade']);
                $this->assertSame('Biología', $row['subject']);
            }
        }
        foreach (['other-group', 'other-year', 'withdrawn', 'unrelated-teacher'] as $role) {
            $this->news($f, $role, 0);
        }
        $this->gradebookAuthenticate($f, 'no-access');
        $this->getJson($f['base'].'/notification-updates')->assertForbidden()->assertHeaderMissing('X-Notification-Record');
        $f['school']->run(function () use ($expected) {
            $this->assertSame($expected * 5, DB::table('user_notification_updates')->count());
            $this->assertSame(1, DB::table('user_notification_records')->count());
            $this->assertSame(User::where('role', 'rector')->value('id'), DB::table('user_notification_records')->value('user_id'));
        });
    }

    public function test_edits_and_reveals_create_dual_notices_without_duplicate_publications(): void
    {
        $f = $this->fixture(false);
        $token = $this->createResource($f);
        $url = $f['base'].'/aula/recursos/'.$token;
        $this->putJson($url, $this->input(true, ['titulo' => 'Cambio oculto', 'version' => 1]))->assertOk();
        $this->news($f, 'estudiante', 0);
        $this->gradebookAuthenticate($f, 'rector');
        $sectionUrl = $f['base'].'/aula/secciones/'.$f['sectionToken'];
        $this->record($f, $this->putJson($sectionUrl, ['visible_estudiantes' => true])->assertOk(), 'aula.section.visibility');
        $this->record($f, $this->putJson($sectionUrl, ['visible_estudiantes' => true])->assertOk(), 'aula.section.update');
        $this->news($f, 'rector', 1)->assertJsonPath('data.0.event', 'aula.resource.published');
        $this->record($f, $this->putJson($url, $this->input(true, ['titulo' => 'Cambio visible', 'version' => 2]))->assertOk(), 'aula.resource.update');
        $this->record($f, $this->putJson($url, $this->input(true, ['titulo' => 'Cambio visible', 'version' => 3]))->assertOk(), 'aula.resource.update');
        $feed = $this->news($f, 'estudiante', 2);
        $this->assertEqualsCanonicalizing(['aula.resource.published', 'aula.resource.updated'], array_column($feed->json('data'), 'event'));
        $this->getJson($url)->assertOk()->assertHeaderMissing('X-Notification-Record');
        $this->news($f, 'estudiante', 2);
        $this->gradebookAuthenticate($f, 'rector');
        $this->record($f, $this->putJson($url, $this->input(false, ['version' => 4]))->assertOk(), 'aula.resource.visibility');
        $this->news($f, 'estudiante', 0);
        $this->gradebookAuthenticate($f, 'rector');
        $this->record($f, $this->putJson($url, $this->input(true, ['version' => 5]))->assertOk(), 'aula.resource.visibility');
        $this->news($f, 'estudiante', 3);
    }

    public function test_future_publication_is_due_once_in_scheduler_and_get_and_survives_rescheduling(): void
    {
        $f = $this->fixture();
        $from = now(tenant()?->zonaHorariaInstitucional() ?? 'America/Bogota')->addHour()->format('Y-m-d\TH:i');
        $token = $this->createResource($f, true, ['disponible_desde' => $from]);
        $this->news($f, 'estudiante', 0);
        $this->artisan('aula:publicar-novedades', ['--tenant' => $f['school']->slug])->assertExitCode(0);
        $this->news($f, 'estudiante', 0);
        Carbon::setTestNow('2028-02-01 13:01:00 UTC');
        $this->artisan('aula:publicar-novedades', ['--tenant' => $f['school']->slug])->assertExitCode(0);
        $this->news($f, 'estudiante', 1)->assertJsonPath('data.0.url', '/evaluacion/aula/recursos/'.$token);
        $this->artisan('aula:publicar-novedades', ['--tenant' => $f['school']->slug])->assertExitCode(0);
        $this->news($f, 'estudiante', 1);
        $f['school']->run(fn () => $this->assertSame(5, DB::table('user_notification_updates')->count()));
    }

    public static function grading(): array
    {
        return ['numeric-submission' => [false, false], 'caritas-submission' => [true, false],
            'numeric-quiz' => [false, true], 'caritas-quiz' => [true, true]];
    }

    #[DataProvider('grading')]
    public function test_grades_are_private_with_numeric_and_caritas_and_errors_record_only_actor(bool $visual, bool $quiz): void
    {
        $f = $this->fixture(true, $visual);
        [$token, $itemToken] = $f['school']->run(function () use ($f, $quiz) {
            $section = OpaqueUrlToken::find('aula-seccion', $f['sectionToken'], AulaSeccion::query());
            $resource = AulaRecurso::create(['seccion_id' => $section->id, 'tipo' => $quiz ? 'cuestionario' : 'tarea',
                'titulo' => 'Trabajo privado', 'estado' => 'publicado', 'visible_estudiantes' => true, 'calificable' => true,
                'llevar_planilla' => false, 'autor_id' => User::where('role', 'rector')->value('id'), 'version' => 1]);
            app(AulaNotifications::class)->resourceChanged($resource, User::where('role', 'rector')->firstOrFail());
            $fields = ['recurso_id' => $resource->id, 'matricula_id' => OpaqueUrlToken::find('matricula', $f['enrollmentToken'], Matricula::query())->id,
                'estado' => $quiz ? 'finalizado' : 'entregada', 'version' => 1];
            $item = $quiz ? AulaIntento::create([...$fields, 'numero' => 1, 'iniciado_at' => now(), 'respuestas' => ['secret' => 'Respuesta secreta']])
                : AulaEntrega::create([...$fields, 'texto' => 'Respuesta secreta', 'entregada_at' => now()]);

            return [OpaqueUrlToken::for('aula-recurso', $resource->id), OpaqueUrlToken::for($quiz ? 'aula-intento' : 'aula-entrega', $item->id)];
        });
        $this->gradebookAuthenticate($f, 'docente');
        $url = $f['base'].'/aula/'.($quiz ? 'intentos' : 'entregas').'/'.$itemToken.'/calificar';
        $input = ['version' => 1, 'nota' => $visual ? null : '4', 'motivo' => 'Razón confidencial', 'retroalimentacion' => 'Feedback secreto'];
        if ($visual) {
            $input['escala_opcion_token'] = $f['choiceToken'];
        }
        $this->record($f, $this->putJson($url, [...$input, 'version' => 0])->assertStatus($quiz ? 409 : 422), 'aula.'.($quiz ? 'quiz' : 'submission').'.grade');
        $this->news($f, 'estudiante', 1);
        $this->gradebookAuthenticate($f, 'docente');
        $this->record($f, $this->putJson($url, $input)->assertOk(), 'aula.'.($quiz ? 'quiz' : 'submission').'.grade');
        $feed = $this->news($f, 'estudiante', 2);
        $grade = collect($feed->json('data'))->firstWhere('event', 'aula.grade.saved');
        $this->assertSame('/evaluacion/aula/recursos/'.$token, $grade['url']);
        $resourceApi = $f['base'].str_replace('/evaluacion/aula/', '/aula/', $grade['url']);
        $opened = $this->getJson($resourceApi)->assertOk()->assertJsonPath('data.estudiante', true)
            ->assertHeaderMissing('X-Notification-Record');
        $ownGrade = $quiz ? $this->getJson($resourceApi.'/mi-intento')->assertOk() : $opened;
        $prefix = $quiz ? 'data' : 'data.entrega';
        if ($visual) {
            $ownGrade->assertJsonPath($prefix.'.nota', null)->assertJsonPath($prefix.'.valoracion.nombre', 'Alcanzado');
        } else {
            $this->assertTrue(BigDecimal::of((string) $ownGrade->json($prefix.'.nota'))->isEqualTo('4'));
        }
        $this->gradebookAuthenticate($f, 'peer');
        $peerResource = $this->getJson($resourceApi)->assertOk()->assertJsonPath('data.estudiante', true);
        if ($quiz) {
            $this->getJson($resourceApi.'/mi-intento')->assertOk()->assertJsonPath('data', null);
            $this->getJson($f['base'].'/aula/intentos/'.$itemToken)->assertForbidden();
        } else {
            $peerResource->assertJsonPath('data.entrega', null);
        }
        foreach (['Respuesta secreta', 'Razón confidencial', 'Feedback secreto', 'escala_opcion', 'matricula_id', 'recurso_id', 'user_id', 'nota'] as $secret) {
            $this->assertStringNotContainsString($secret, $feed->getContent());
        }
        foreach (['peer', 'rector', 'docente', 'admin'] as $role) {
            $rows = $this->news($f, $role, 1)->json('data');
            $this->assertNotContains('aula.grade.saved', array_column($rows, 'event'));
        }
        $f['school']->run(function () use ($visual, $quiz) {
            $item = $quiz ? AulaIntento::firstOrFail() : AulaEntrega::firstOrFail();
            $this->assertTrue(BigDecimal::of((string) $item->nota)->isEqualTo('4'));
            $this->assertSame($visual ? EscalaOpcion::where('nombre', 'Alcanzado')->value('id') : null, $item->escala_opcion_id);
            $this->assertSame(1, DB::table('user_notification_updates')->where('event', 'aula.grade.saved')->count());
            $this->assertSame(2, DB::table('user_notification_records')->count());
            $this->assertSame([User::where('role', 'docente')->value('id')], DB::table('user_notification_records')->distinct()->pluck('user_id')->all());
        });
    }

    public function test_withdrawn_deleted_archived_and_inaccessible_entries_are_filtered_before_pagination(): void
    {
        $f = $this->fixture();
        $token = $this->createResource($f);
        $url = $f['base'].'/aula/recursos/'.$token;
        $this->record($f, $this->deleteJson($url)->assertOk(), 'aula.resource.destroy');
        $this->news($f, 'estudiante', 0);
        $this->gradebookAuthenticate($f, 'rector');
        $this->record($f, $this->postJson($url.'/restaurar')->assertOk(), 'aula.resource.restore');
        $this->news($f, 'estudiante', 2);
        $this->gradebookAuthenticate($f, 'rector');
        $this->record($f, $this->postJson($url.'/archivar', ['version' => 1])->assertOk(), 'aula.resource.archive');
        $this->news($f, 'estudiante', 0);
        $this->gradebookAuthenticate($f, 'rector');
        $this->putJson($url, $this->input(true, ['version' => 2]))->assertOk();
        $this->news($f, 'estudiante', 3);
        $f['school']->run(fn () => Matricula::where('estudiante_id', User::where('email', 'estudiante@gradebook.test')->value('id'))->update(['estado' => 'retirada']));
        $this->news($f, 'estudiante', 0);
        $this->news($f, 'peer', 3);
        $f['school']->run(fn () => User::where('email', 'peer@aula.test')->firstOrFail()->removeRole('estudiante'));
        $this->getJson($f['base'].'/notification-updates')->assertForbidden()->assertHeaderMissing('X-Notification-Record');
        $this->gradebookAuthenticate($f, 'rector');
        $this->deleteJson($f['base'].'/aula/secciones/'.$f['sectionToken'])->assertOk();
        $this->news($f, 'rector', 0);
        $this->getJson($f['base'].'/notification-updates?page=0')->assertUnprocessable();
    }

    public function test_feed_tenant_account_isolation_and_opaque_numeric_route_rejection(): void
    {
        $f = $this->fixture();
        $token = $this->createResource($f);
        $resourceId = $f['school']->run(fn () => AulaRecurso::firstOrFail()->id);
        $this->record($f, $this->putJson($f['base'].'/aula/recursos/'.$resourceId, $this->input(true, ['version' => 1]))->assertNotFound(), 'aula.resource.update');
        $this->getJson($f['base'].'/aula/recursos/'.$resourceId)->assertNotFound()->assertHeaderMissing('X-Notification-Record');
        $g = $this->fixture();
        $this->news($g, 'rector', 0);
        $this->record($g, $this->putJson($g['base'].'/aula/recursos/'.$token, $this->input(true, ['version' => 1]))->assertNotFound(), 'aula.resource.update');
        $this->news($g, 'estudiante', 0);
        $this->news($f, 'estudiante', 1);
        $this->news($f, 'unrelated-teacher', 0);
        $this->gradebookAuthenticate($g, 'rector');
        $this->getJson($f['base'].'/notification-updates')->assertUnauthorized();
    }

    public function test_migration_baselines_old_visible_content_and_first_edit_is_updated(): void
    {
        $f = $this->fixture();
        $token = $f['school']->run(function () use ($f) {
            $resource = AulaRecurso::create(['seccion_id' => OpaqueUrlToken::find('aula-seccion', $f['sectionToken'], AulaSeccion::query())->id,
                'tipo' => 'texto', 'titulo' => 'Histórico', 'estado' => 'publicado', 'visible_estudiantes' => true,
                'autor_id' => User::where('role', 'rector')->value('id'), 'version' => 1]);
            $migration = require base_path('database/migrations/tenant/2026_11_06_000002_create_aula_notification_updates.php');
            $migration->down();
            $migration->up();
            // Replaying the baseline also requires later extensions of the shared feed.
            (require base_path('database/migrations/tenant/2026_11_06_000003_add_attendance_notification_updates.php'))->up();
            (require base_path('database/migrations/tenant/2026_11_06_000004_add_calendar_notification_updates.php'))->up();

            return OpaqueUrlToken::for('aula-recurso', $resource->id);
        });
        $this->news($f, 'estudiante', 0);
        $this->gradebookAuthenticate($f, 'rector');
        $this->putJson($f['base'].'/aula/recursos/'.$token, $this->input(true, ['version' => 1, 'titulo' => 'Histórico editado']))->assertOk();
        $this->news($f, 'estudiante', 1)->assertJsonPath('data.0.event', 'aula.resource.updated');
    }

    public function test_quiz_manual_answer_save_records_but_autosave_reads_and_page_do_not(): void
    {
        $f = $this->fixture();
        $token = $this->createResource($f, false, ['tipo' => 'cuestionario', 'calificable' => true]);
        $url = $f['base'].'/aula/recursos/'.$token;
        $saved = $this->putJson($url.'/preguntas', ['version' => 1, 'preguntas' => [
            ['tipo' => 'abierta', 'enunciado' => 'Explica el concepto', 'puntos' => 5],
        ]])->assertOk();
        $this->record($f, $saved, 'aula.quiz.save');
        $questionToken = $saved->json('data.preguntas.0.token');
        $this->putJson($url, $this->input(true, ['tipo' => 'cuestionario', 'calificable' => true, 'version' => 2]))->assertOk();
        $this->gradebookAuthenticate($f, 'estudiante');
        $started = $this->postJson($url.'/intentos')->assertOk();
        $this->record($f, $started, 'aula.quiz.start');
        $attemptUrl = $f['base'].'/aula/intentos/'.$started->json('data.token');
        $answers = ['respuestas' => [$questionToken => 'Respuesta confidencial']];
        $this->putJson($attemptUrl.'/respuestas', $answers)->assertOk()->assertHeaderMissing('X-Notification-Record');
        $this->putJson($attemptUrl.'/respuestas', [...$answers, 'registrar_accion' => false])->assertOk()->assertHeaderMissing('X-Notification-Record');
        $this->record($f, $this->putJson($attemptUrl.'/respuestas', [...$answers, 'registrar_accion' => true])->assertOk(), 'aula.quiz.saveAnswers');
        $this->record($f, $this->putJson($attemptUrl.'/respuestas', ['respuestas' => [], 'registrar_accion' => true])->assertUnprocessable(), 'aula.quiz.saveAnswers');
        $this->getJson($attemptUrl)->assertOk()->assertHeaderMissing('X-Notification-Record');
        $this->putJson($attemptUrl.'/pagina', ['pagina' => 1])->assertOk()->assertHeaderMissing('X-Notification-Record');
        $finished = $this->postJson($attemptUrl.'/finalizar')->assertOk();
        $this->record($f, $finished, 'aula.quiz.finish');
        $this->news($f, 'estudiante', 1);
        $this->gradebookAuthenticate($f, 'docente');
        $version = $f['school']->run(fn () => AulaIntento::firstOrFail()->version);
        $review = $this->putJson($attemptUrl.'/revisar-preguntas', ['version' => $version,
            'motivo' => 'Revisión confidencial', 'evaluaciones' => [['pregunta_token' => $questionToken,
                'puntos' => 4, 'retroalimentacion' => 'Comentario privado']]])->assertOk();
        $this->record($f, $review, 'aula.quiz.review');
        $this->news($f, 'estudiante', 2);
        $this->news($f, 'peer', 1);
        $this->gradebookAuthenticate($f, 'docente');
        $this->record($f, $this->postJson($attemptUrl.'/reintentar-planilla', ['version' => $review->json('data.version')])->assertUnprocessable(), 'aula.quiz.retry');
        $this->record($f, $this->postJson($attemptUrl.'/reactivar', ['version' => $review->json('data.version')])->assertUnprocessable(), 'aula.quiz.reactivate');
    }

    public function test_attachments_configuration_submission_review_and_section_lifecycle_are_recorded(): void
    {
        $f = $this->fixture();
        Storage::fake('tenant');
        config()->set('storage.scanner', 'null');
        $this->record($f, $this->putJson($f['base'].'/aula/configuracion', ['permitir_edicion_periodos_cerrados' => false])->assertOk(), 'aula.configuration.save');
        $token = $this->createResource($f, true, ['tipo' => 'tarea']);
        $url = $f['base'].'/aula/recursos/'.$token;
        $uploaded = $this->post($url.'/adjuntos', ['archivo' => UploadedFile::fake()->createWithContent('guia.pdf', '%PDF-1.4 Material de apoyo')])->assertCreated();
        $this->record($f, $uploaded, 'aula.attachment.upload');
        $this->record($f, $this->deleteJson($f['base'].'/aula/adjuntos/'.$uploaded->json('data.token'))->assertOk(), 'aula.attachment.destroy');
        $this->news($f, 'estudiante', 3);
        $this->gradebookAuthenticate($f, 'estudiante');
        $sent = $this->postJson($url.'/entregas', ['texto' => 'Mi respuesta secreta'])->assertCreated();
        $this->record($f, $sent, 'aula.submission.send');
        $this->gradebookAuthenticate($f, 'docente');
        $review = $this->putJson($f['base'].'/aula/entregas/'.$sent->json('data.token').'/revisar',
            ['version' => 1, 'retroalimentacion' => 'Detalle privado'])->assertOk();
        $this->record($f, $review, 'aula.submission.review');
        $this->news($f, 'estudiante', 4);
        $this->news($f, 'peer', 3);
        $this->gradebookAuthenticate($f, 'rector');
        $sectionUrl = $f['base'].'/aula/secciones/'.$f['sectionToken'];
        $this->record($f, $this->deleteJson($sectionUrl)->assertOk(), 'aula.section.destroy');
        $this->news($f, 'estudiante', 0);
        $this->gradebookAuthenticate($f, 'rector');
        $this->record($f, $this->postJson($sectionUrl.'/restaurar')->assertOk(), 'aula.section.restore');
        $this->news($f, 'estudiante', 5);
        $this->gradebookAuthenticate($f, 'rector');
        $this->record($f, $this->postJson($f['base'].'/aula/'.$f['aulaToken'].'/secciones', [
            'periodo_token' => $f['periodToken'], 'titulo' => 'Otra sección', 'visible_estudiantes' => false,
        ])->assertCreated(), 'aula.section.store');
        $this->record($f, $this->postJson($url.'/vincular-planilla')->assertUnprocessable(), 'aula.resource.link');
    }

    public function test_grading_hidden_or_future_content_never_creates_recipient_notices(): void
    {
        $f = $this->fixture(false);
        $token = $this->createResource($f, true, ['tipo' => 'tarea', 'calificable' => true]);
        $itemToken = $f['school']->run(function () use ($f) {
            $submission = AulaEntrega::create(['recurso_id' => AulaRecurso::firstOrFail()->id,
                'matricula_id' => OpaqueUrlToken::find('matricula', $f['enrollmentToken'], Matricula::query())->id,
                'estado' => 'entregada', 'version' => 1, 'texto' => 'Respuesta privada', 'entregada_at' => now()]);

            return OpaqueUrlToken::for('aula-entrega', $submission->id);
        });
        $this->gradebookAuthenticate($f, 'docente');
        $this->record($f, $this->putJson($f['base'].'/aula/entregas/'.$itemToken.'/calificar',
            ['version' => 1, 'nota' => 4, 'retroalimentacion' => 'Comentario privado'])->assertOk(), 'aula.submission.grade');
        $this->news($f, 'estudiante', 0);
        $f['school']->run(function () {
            $student = User::where('email', 'estudiante@gradebook.test')->firstOrFail();
            $actor = User::where('role', 'docente')->firstOrFail();
            foreach ([['borrador', true, null], ['archivado', true, null], ['publicado', false, null],
                ['programado', true, now('UTC')->addHour()], ['programado', true, null]] as [$state, $visible, $from]) {
                $resource = AulaRecurso::firstOrFail();
                $resource->seccion->update(['visible_estudiantes' => true]);
                $resource->update(['estado' => $state, 'visible_estudiantes' => $visible, 'disponible_desde' => $from]);
                app(AulaNotifications::class)->gradeSaved(AulaEntrega::firstOrFail(), $actor);
            }
            $this->assertSame(0, DB::table('user_notification_updates')->where('user_id', $student->id)->count());
        });
    }

    public function test_pagination_counts_only_readable_rows_and_feed_queries_do_not_grow_per_resource(): void
    {
        $f = $this->fixture();
        $token = $this->createResource($f);
        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });
        $this->news($f, 'estudiante', 1);
        $initialQueries = $queryCount;
        $f['school']->run(function () {
            $student = User::where('email', 'estudiante@gradebook.test')->firstOrFail();
            $original = AulaRecurso::firstOrFail();
            $rows = [];
            for ($index = 0; $index < 24; $index++) {
                $resource = $original->replicate();
                $resource->titulo = 'Recurso '.$index;
                $resource->save();
                DB::table('aula_notification_publications')->insert(['recurso_id' => $resource->id, 'visible' => true, 'revision' => 1]);
                $rows[] = ['url_token' => Str::random(24), 'user_id' => $student->id, 'recurso_id' => $resource->id,
                    'event' => 'aula.resource.published', 'deduplication_key' => 'resource:1', 'created_at' => now()->addSeconds($index + 1)];
            }
            DB::table('user_notification_updates')->insert($rows);
        });
        $queryCount = 0;
        $first = $this->news($f, 'estudiante', 25)->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2);
        $this->assertLessThanOrEqual($initialQueries + 8, $queryCount);
        $second = $this->getJson($f['base'].'/notification-updates?page=2')->assertOk()->assertJsonCount(5, 'data');
        $this->assertEmpty(array_intersect(array_column($first->json('data'), 'token'), array_column($second->json('data'), 'token')));
        $f['school']->run(fn () => AulaRecurso::where('titulo', 'like', 'Recurso %')->update(['visible_estudiantes' => false]));
        $this->news($f, 'estudiante', 1)->assertJsonPath('meta.last_page', 1)->assertJsonPath('data.0.url', '/evaluacion/aula/recursos/'.$token);
    }

    public function test_content_and_grade_roll_back_with_recipient_notices_if_notification_step_fails(): void
    {
        $f = $this->fixture();
        $real = app(AulaNotifications::class);
        $failing = new class($real)
        {
            public function __construct(private AulaNotifications $real) {}

            public function resourceChanged(AulaRecurso $resource, ?User $actor = null, bool $edited = false): void
            {
                $this->real->resourceChanged($resource, $actor, $edited);
                throw new \RuntimeException('Simulated notification persistence failure');
            }

            public function gradeSaved(AulaEntrega|AulaIntento $item, User $actor): void
            {
                $this->real->gradeSaved($item, $actor);
                throw new \RuntimeException('Simulated notification persistence failure');
            }
        };
        app()->instance(AulaNotifications::class, $failing);
        $this->record($f, $this->postJson($f['base'].'/aula/secciones/'.$f['sectionToken'].'/recursos', $this->input())->assertStatus(500), 'aula.resource.store');
        app()->forgetInstance(AulaNotifications::class);
        $f['school']->run(function () {
            $this->assertSame(0, AulaRecurso::count());
            $this->assertSame(0, DB::table('user_notification_updates')->count());
            $this->assertSame(0, DB::table('aula_notification_publications')->count());
        });
        $token = $this->createResource($f, true, ['tipo' => 'tarea', 'calificable' => true]);
        $itemToken = $f['school']->run(function () use ($f) {
            $item = AulaEntrega::create(['recurso_id' => AulaRecurso::firstOrFail()->id,
                'matricula_id' => OpaqueUrlToken::find('matricula', $f['enrollmentToken'], Matricula::query())->id,
                'texto' => 'Respuesta', 'estado' => 'entregada', 'version' => 1, 'entregada_at' => now()]);

            return OpaqueUrlToken::for('aula-entrega', $item->id);
        });
        $this->gradebookAuthenticate($f, 'docente');
        app()->instance(AulaNotifications::class, $failing);
        $this->record($f, $this->putJson($f['base'].'/aula/entregas/'.$itemToken.'/calificar', ['version' => 1, 'nota' => 4])->assertStatus(500), 'aula.submission.grade');
        app()->forgetInstance(AulaNotifications::class);
        $f['school']->run(function () {
            $this->assertNull(AulaEntrega::firstOrFail()->nota);
            $this->assertSame(1, AulaEntrega::firstOrFail()->version);
            $this->assertSame(0, DB::table('user_notification_updates')->where('event', 'aula.grade.saved')->count());
        });
        $this->news($f, 'estudiante', 1)->assertJsonPath('data.0.url', '/evaluacion/aula/recursos/'.$token);
    }

    public function test_active_sede_scheduler_uses_its_database_and_denied_feed_cannot_reconcile(): void
    {
        $f = $this->fixture();
        $this->createResource($f, true, ['disponible_desde' => '2028-02-02T10:00']);
        Carbon::setTestNow('2028-02-02 16:00:00 UTC');
        $this->gradebookAuthenticate($f, 'no-access');
        $this->getJson($f['base'].'/notification-updates')->assertForbidden()->assertHeaderMissing('X-Notification-Record');
        $f['school']->run(fn () => $this->assertSame(0, DB::table('user_notification_updates')->count()));
        // An independently provisioned database now represents an active child campus.
        $f['school']->update(['tipo' => Tenant::TIPO_SEDE]);
        $this->artisan('aula:publicar-novedades', ['--tenant' => $f['school']->slug])->assertExitCode(0);
        $f['school']->run(fn () => $this->assertSame(5, DB::table('user_notification_updates')->count()));
        $this->artisan('aula:publicar-novedades', ['--tenant' => $f['school']->slug])->assertExitCode(0);
        $f['school']->run(fn () => $this->assertSame(5, DB::table('user_notification_updates')->count()));
        $this->gradebookAuthenticate($f, 'estudiante');
        $this->record($f, $this->putJson($f['base'].'/aula/recursos/'.Str::random(24), $this->input(true, ['titulo' => 'Dato privado']))->assertNotFound(), 'aula.resource.update');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $database = (string) config('database.connections.pgsql.database');
        if (config('database.default') !== 'pgsql'
            || ! preg_match('/^codex_notifications_test_[a-f0-9]{12}$/D', $database)) {
            throw new \RuntimeException('Use scripts/test-notifications-postgres.php with disposable PostgreSQL databases.');
        }
        $this->assertSame($database, DB::selectOne('select current_database() as name')->name);
        $this->artisan('migrate:fresh', ['--database' => 'pgsql', '--force' => true])->assertExitCode(0);
        DatabaseConfig::generateDatabaseNamesUsing(
            fn ($tenant) => $database.'_t_'.substr(hash('sha256', $tenant->getTenantKey()), 0, 10)
        );
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->seed(DatabaseSeeder::class);
        Carbon::setTestNow('2028-02-01 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        DB::setDefaultConnection(config('tenancy.database.central_connection'));
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gradebookFixture(bool $visual = false, array $siee = []): array
    {
        ['tenant' => $school] = app(TenantProvisioner::class)->provision([
            'name' => 'Planilla de prueba', 'slug' => 'planilla-notificaciones-'.uniqid(),
            'plan' => Tenant::PLAN_PREMIUM, 'rector_email' => 'rector@gradebook.test',
        ]);
        $school->update(['status' => Tenant::STATUS_ACTIVE]);
        $this->gradebookSchool = $school;
        $tokens = $school->run(function () use ($visual, $siee) {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $year = AnoLectivo::create(['nombre' => '2028', 'tipo_calendario' => 'A', 'estado' => 'en_curso',
                'fecha_inicio' => '2028-01-01', 'fecha_fin' => '2028-12-31', 'num_periodos' => 1, 'periodo_sumatorio' => true]);
            $scale = EscalaValorativa::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Numérica',
                'tipo' => 'numerica', 'valor_min' => '0', 'valor_max' => '5', 'decimales' => 1]);
            $method = MetodoAprobacion::create(['ano_lectivo_id' => $year->id, 'calculo_nota' => 'promedio_simple',
                'nota_minima' => '3', 'ambito' => 'materia']);
            $year->update(['siee' => [...SieeConfiguration::DEFAULTS, ...$siee, 'escala_id' => $scale->id, 'metodo_id' => $method->id]]);
            $period = Periodo::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Único', 'orden' => 1,
                'fecha_inicio' => '2028-01-01', 'fecha_fin' => '2028-12-31', 'peso' => 100, 'estado' => 'abierto']);
            $campus = Sede::create(['nombre' => 'Principal', 'estado' => 'activa']);
            $shift = Jornada::create(['ano_lectivo_id' => $year->id, 'sede_id' => $campus->id,
                'nombre' => 'Mañana', 'hora_inicio' => '07:00', 'hora_fin' => '12:00', 'estado' => 'activa']);
            $level = Nivel::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Primaria', 'nivel_educativo' => 'basica_primaria', 'estado' => 'activo']);
            $grade = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $level->id, 'nombre' => 'Primero', 'estado' => 'activo']);
            $group = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'jornada_id' => $shift->id,
                'sede_id' => $campus->id, 'nombre' => '01A', 'estado' => 'activo']);
            $area = Area::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Ciencias', 'estado' => 'activo']);
            $subject = Materia::create(['ano_lectivo_id' => $year->id, 'area_id' => $area->id,
                'nombre' => 'Biología', 'intensidad_horaria' => 2, 'estado' => 'activo']);
            DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id,
                'materia_id' => $subject->id, 'area_id' => $area->id, 'peso_area' => '100']);
            $bearers = ['rector' => $rector->createToken('gradebook-test')->plainTextToken];
            foreach (['docente' => 'Docente de prueba', 'estudiante' => 'Estudiante confidencial'] as $role => $name) {
                $user = User::create(['name' => $name, 'email' => $role.'@gradebook.test', 'password' => bcrypt('Temporary1!abc'),
                    'role' => $role, 'status' => 'active', 'must_change_password' => false]);
                $user->assignRole($role);
                $bearers[$role] = $user->createToken('gradebook-test')->plainTextToken;
            }
            $assignment = AsignacionDocente::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $group->id,
                'materia_id' => $subject->id, 'docente_id' => User::where('role', 'docente')->firstOrFail()->id]);
            $enrollment = Matricula::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $group->id,
                'estudiante_id' => User::where('role', 'estudiante')->firstOrFail()->id, 'estado' => 'activa']);
            $component = ComponenteEvaluacion::create(['asignacion_id' => $assignment->id, 'periodo_id' => $period->id,
                'nombre' => 'Actividades', 'peso' => 100, 'modo' => 'SIMPLE_AVERAGE', 'es_directo' => false, 'version' => 1]);
            $activity = ActividadEvaluacion::create(['componente_id' => $component->id, 'nombre' => 'Taller', 'fecha' => '2028-02-01', 'version' => 1]);
            $choiceToken = null;
            if ($visual) {
                $faces = EscalaValorativa::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Caritas',
                    'nivel_educativo' => $level->nivel_educativo, 'tipo' => 'imagenes']);
                $choice = EscalaOpcion::create(['escala_id' => $faces->id, 'nombre' => 'Alcanzado', 'orden' => 1,
                    'valor_equivalente' => '4', 'emoji' => '🙂', 'aprueba' => true]);
                EscalaOpcion::create(['escala_id' => $faces->id, 'nombre' => 'En proceso', 'orden' => 2,
                    'valor_equivalente' => '2', 'emoji' => '😐', 'aprueba' => false]);
                $choiceToken = OpaqueUrlToken::for('escala-opcion', $choice->id);
            }

            return ['bearers' => $bearers, 'choiceToken' => $choiceToken,
                'assignmentToken' => OpaqueUrlToken::for('asignacion-docente', $assignment->id),
                'periodToken' => OpaqueUrlToken::for('periodo', $period->id),
                'componentToken' => OpaqueUrlToken::for('componente-evaluacion', $component->id),
                'activityToken' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id),
                'enrollmentToken' => OpaqueUrlToken::for('matricula', $enrollment->id),
                'groupToken' => OpaqueUrlToken::for('grupo', $group->id), 'areaToken' => OpaqueUrlToken::for('area', $area->id),
                'subjectToken' => OpaqueUrlToken::for('materia', $subject->id)];
        });
        $base = 'http://'.$school->slug.'.localhost/api';
        $fixture = [...$tokens, 'school' => $school, 'base' => $base,
            'sheet' => $base.'/evaluacion/planillas/'.$tokens['assignmentToken'].'/'.$tokens['periodToken']];
        $this->gradebookAuthenticate($fixture, 'rector');

        return $fixture;
    }

    private function gradebookAuthenticate(array $fixture, string $role): void
    {
        $this->gradebookActor = $role;
        $this->withToken($fixture['bearers'][$role]);
        app('auth')->forgetGuards();
    }
}
