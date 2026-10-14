<?php

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\Aula;
use App\Models\Academico\AulaSeccion;
use App\Models\Academico\AulaRecurso;
use App\Models\Academico\Evento;
use App\Models\Academico\EventoCorreo;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Plan;
use App\Models\User;
use App\Services\EventMailOutbox;
use App\Services\SchoolMail;
use App\Services\TaskCalendar;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

trait EventWorkflowTests
{
    private function eventApi(User $user): string
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        app('auth')->forgetGuards();
        $this->withHeader('X-Tenant', $this->school->id)->withToken($user->createToken('test-events')->plainTextToken);
        return 'http://localhost/api/eventos';
    }

    private function eventInput(array $changes = []): array
    {
        return [...['titulo' => 'Feria de ciencias', 'descripcion' => 'Proyectos del grupo', 'fecha' => '2026-10-20',
            'categoria' => 'actividad', 'institucional' => false, 'grupo_tokens' => [OpaqueUrlToken::for('grupo', $this->group->id)],
            'enviar_correo' => false], ...$changes];
    }

    private function eventStudent(string $email, ?Grupo $group = null): User
    {
        Role::findOrCreate('estudiante', 'web');
        $student = User::create(['name' => 'Estudiante sintético', 'email' => $email, 'password' => 'TestingPassword123!', 'role' => 'estudiante', 'status' => 'active']);
        $student->assignRole('estudiante');
        Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => ($group ?? $this->group)->id,
            'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        return $student;
    }

    private function eventMailReady(): void
    {
        $this->school->domains()->firstOrCreate(['domain' => 'academic-test.localhost']);
        $this->mock(SchoolMail::class, fn ($mock) => $mock->shouldReceive('readyForEvents')->andReturn(true));
    }

    public function test_event_workflow_drafts_are_private_tasks_rejected_and_publication_is_idempotent(): void
    {
        $student = $this->eventStudent('event-student@test.test');
        $api = $this->eventApi($this->teacher);
        $this->postJson($api.'?opaque=1', $this->eventInput(['categoria' => 'tarea']))->assertUnprocessable();
        $created = $this->postJson($api.'?opaque=1', $this->eventInput())->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')->assertJsonPath('data.publicado_en', null)->json('data');
        $token = $created['url_token'];
        $this->eventApi($student);
        $this->getJson("$api/$token?opaque=1")->assertNotFound();
        $this->getJson("$api?opaque=1&desde=2026-10-01&hasta=2026-10-31")->assertJsonCount(0, 'data');
        $this->postJson("$api/$token/publicar?opaque=1", ['version' => 1])->assertForbidden();
        $this->eventApi($this->teacher);
        $this->postJson("$api/$token/publicar?opaque=1", ['version' => 8])->assertConflict();
        $this->postJson("$api/$token/publicar?opaque=1", ['version' => 1])->assertOk()->assertJsonPath('data.estado', 'publicado');
        $date = Evento::first()->publicado_en;
        $this->postJson("$api/$token/publicar?opaque=1", ['version' => 1])->assertOk();
        $this->assertEquals($date, Evento::first()->publicado_en);
        $this->assertSame(0, EventoCorreo::count());
        $this->eventApi($student);
        $this->getJson("$api/$token?opaque=1")->assertOk()->assertJsonMissingPath('data.correos');
    }

    public function test_event_calendar_summary_and_cursor_feed_limit_large_days_and_keep_other_drafts_private(): void
    {
        $api = $this->eventApi($this->rector);
        for ($i = 0; $i < 61; $i++) {
            $event = Evento::create(['titulo' => 'Encuentro de lectura '.$i, 'descripcion' => 'Actividad del grupo',
                'fecha' => '2026-10-20', 'categoria' => $i % 2 ? 'reunion' : 'actividad',
                'institucional' => false, 'created_by' => $this->rector->id, 'estado' => 'publicado',
                'publicado_en' => now(), 'version' => 2, 'enviar_correo' => false]);
            $event->grupos()->sync([$this->group->id]);
        }
        $this->eventApi($this->teacher);
        $private = $this->postJson($api.'?opaque=1', $this->eventInput(['titulo' => 'Borrador privado']))
            ->assertCreated()->json('data.url_token');
        $this->eventApi($this->rector);
        $summary = $this->getJson($api.'/resumen?opaque=1&desde=2026-10-01&hasta=2026-10-31')
            ->assertOk()->json('data');
        $this->assertSame(61, $summary[0]['total']);
        $this->assertNull($summary[0]['titulo_unico']);
        $this->getJson($api.'/resumen?opaque=1&desde=2026-10-01&hasta=2026-10-31&categoria=')
            ->assertOk()->assertJsonPath('data.0.total', 61);
        $first = $this->getJson($api.'/flujo?opaque=1&desde=2026-10-01&hasta=2026-10-31')
            ->assertOk()->json();
        $this->assertCount(50, $first['data']);
        $this->assertNotNull($first['next_cursor']);
        $this->assertSame('publicado', $first['data'][0]['estado']);
        $this->assertSame($this->group->grado->nombre, $first['data'][0]['grupos'][0]['grado']['nombre']);
        $cursor = urlencode($first['next_cursor']);
        $second = $this->getJson($api.'/flujo?opaque=1&desde=2026-10-01&hasta=2026-10-31&cursor='.$cursor.'&limite=10')
            ->assertOk()->json();
        $this->assertCount(10, $second['data']);
        $this->assertEmpty(array_intersect(array_column($first['data'], 'url_token'), array_column($second['data'], 'url_token')));
        $last = $this->getJson($api.'/flujo?opaque=1&desde=2026-10-01&hasta=2026-10-31&cursor='.urlencode($second['next_cursor']).'&limite=10')
            ->assertOk()->json();
        $this->assertCount(1, $last['data']);
        $this->assertNull($last['next_cursor']);
        $this->assertEmpty(array_intersect(array_column($first['data'], 'url_token'), array_column($last['data'], 'url_token')));
        $this->assertEmpty(array_intersect(array_column($second['data'], 'url_token'), array_column($last['data'], 'url_token')));
        $activitySummary = $this->getJson($api.'/resumen?opaque=1&desde=2026-10-01&hasta=2026-10-31&categoria=actividad')
            ->assertOk()->json('data');
        $this->assertSame(31, $activitySummary[0]['total']);
        $this->assertSame('actividad', $activitySummary[0]['marcas'][0]['categoria']);
        $meetingFeed = $this->getJson($api.'/flujo?opaque=1&desde=2026-10-01&hasta=2026-10-31&categoria=reunion')
            ->assertOk()->json();
        $this->assertCount(30, $meetingFeed['data']);
        $this->assertNull($meetingFeed['next_cursor']);
        $this->assertEmpty(array_filter($meetingFeed['data'], fn ($event) => $event['categoria'] !== 'reunion'));
        $this->getJson($api.'/resumen?opaque=1&desde=2026-10-01&hasta=2026-10-31&categoria=tarea')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($api.'/flujo?opaque=1&desde=2026-10-01&hasta=2026-10-31&categoria=invalida')
            ->assertUnprocessable();
        $this->getJson($api.'/'.$private.'?opaque=1')->assertNotFound();
    }

    public function test_event_workflow_mail_only_targets_active_students_in_scope_once(): void
    {
        $this->eventMailReady();
        $included = $this->eventStudent('included@test.test');
        $inactive = $this->eventStudent('inactive@test.test');
        $inactive->update(['status' => 'suspended']);
        $withdrawn = $this->eventStudent('withdrawn@test.test');
        Matricula::where('estudiante_id', $withdrawn->id)->update(['estado' => 'retirada']);
        $otherGroup = $this->group->replicate(); $otherGroup->nombre = 'B'; $otherGroup->save();
        $outside = $this->eventStudent('outside@test.test', $otherGroup);
        // Even a mistaken enrollment does not turn a teacher into a student recipient.
        Matricula::create(['estudiante_id' => $this->teacher->id, 'grupo_id' => $this->group->id, 'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        $api = $this->eventApi($this->teacher);
        $token = $this->postJson($api.'?opaque=1', $this->eventInput(['enviar_correo' => true]))->assertCreated()->json('data.url_token');
        $this->assertSame(0, EventoCorreo::count());
        $this->postJson("$api/$token/publicar?opaque=1", ['version' => 1])->assertOk();
        $this->postJson("$api/$token/publicar?opaque=1", ['version' => 1])->assertOk();
        $this->assertSame([$included->id], EventoCorreo::pluck('estudiante_id')->all());
        $this->assertStringNotContainsString($included->email, DB::table('evento_correos')->value('email'));
        $detail = $this->getJson("$api/$token?opaque=1")->assertOk()->assertJsonPath('data.correos.pendiente', 1)->json('data');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id|email)"\s*:/', json_encode($detail));
        $this->eventApi($this->rector);
        $schoolToken = $this->postJson($api.'?opaque=1', $this->eventInput(['institucional' => true, 'grupo_tokens' => [], 'enviar_correo' => true]))->assertCreated()->json('data.url_token');
        $this->postJson("$api/$schoolToken/publicar?opaque=1", ['version' => 1])->assertOk();
        $this->assertEqualsCanonicalizing([$included->id, $outside->id], EventoCorreo::where('evento_id', Evento::latest('id')->first()->id)->pluck('estudiante_id')->all());
    }

    public function test_event_workflow_missing_mail_configuration_rolls_back_publication(): void
    {
        $this->mock(SchoolMail::class, fn ($mock) => $mock->shouldReceive('readyForEvents')->andReturn(false));
        $api = $this->eventApi($this->rector);
        $token = $this->postJson($api.'?opaque=1', $this->eventInput(['enviar_correo' => true]))->json('data.url_token');
        $this->postJson("$api/$token/publicar?opaque=1", ['version' => 1])->assertUnprocessable();
        $this->assertSame('borrador', Evento::first()->estado);
        $this->assertNull(Evento::first()->publicado_en);
        $this->assertSame(0, EventoCorreo::count());
    }

    public function test_event_workflow_teacher_cannot_bypass_assignment_with_legacy_setting(): void
    {
        DB::table('configuracion_eventos')->insert(['id' => 1, 'docentes_cualquier_grupo' => true]);
        $other = $this->group->replicate(); $other->nombre = 'B'; $other->save();
        $api = $this->eventApi($this->teacher);
        $this->postJson($api.'?opaque=1', $this->eventInput(['grupo_tokens' => [OpaqueUrlToken::for('grupo', $other->id)]]))->assertForbidden();
    }

    public function test_event_workflow_batches_rate_limit_retry_failures_and_never_resend_successes(): void
    {
        $this->eventMailReady();
        foreach (range(1, 12) as $i) $this->eventStudent("batch-$i@test.test");
        $api = $this->eventApi($this->rector);
        $token = $this->postJson($api.'?opaque=1', $this->eventInput(['enviar_correo' => true]))->json('data.url_token');
        $this->postJson("$api/$token/publicar?opaque=1", ['version' => 1])->assertOk();
        $count = 0;
        $this->mock(SchoolMail::class, function ($mock) use (&$count) {
            $mock->shouldReceive('sendEvent')->andReturnUsing(function () use (&$count) {
                if (++$count === 1) throw new \RuntimeException('synthetic SMTP outage');
            });
        });
        Cache::flush();
        $outbox = app(EventMailOutbox::class);
        $outbox->deliverBatch();
        $this->assertSame(10, $count);
        $this->assertSame(9, EventoCorreo::where('estado', 'enviado')->count());
        $this->assertSame('transport_failed', EventoCorreo::first()->ultimo_error);
        $outbox->deliverBatch();
        $this->assertSame(10, $count);
        $this->travel(61)->seconds();
        $outbox->deliverBatch();
        $this->assertSame(13, $count);
        $this->assertSame(12, EventoCorreo::where('estado', 'enviado')->count());
        $this->travel(61)->seconds(); $outbox->deliverBatch();
        $this->assertSame(13, $count);
        $this->travelBack();
    }

    public function test_event_workflow_exhausted_delivery_can_be_retried_and_deletion_cancels_pending(): void
    {
        $this->eventMailReady(); $this->eventStudent('failure@test.test');
        $api = $this->eventApi($this->rector);
        $token = $this->postJson($api.'?opaque=1', $this->eventInput(['enviar_correo' => true]))->json('data.url_token');
        $this->postJson("$api/$token/publicar?opaque=1", ['version' => 1])->assertOk();
        $this->mock(SchoolMail::class, fn ($mock) => $mock->shouldReceive('sendEvent')->andThrow(new \RuntimeException('synthetic')));
        EventoCorreo::first()->update(['intentos' => 4]);
        Cache::flush(); app(EventMailOutbox::class)->deliverBatch();
        $this->assertSame('fallido', EventoCorreo::first()->estado);
        $this->postJson("$api/$token/correos/reintentar?opaque=1")->assertOk();
        $this->assertSame(0, EventoCorreo::first()->intentos);
        $this->assertSame('pendiente', EventoCorreo::first()->estado);
        $this->deleteJson("$api/$token?opaque=1")->assertOk();
        $this->assertSame('cancelado', EventoCorreo::first()->estado);
    }

    private function calendarTask(): AulaRecurso
    {
        Plan::updateOrCreate(['key' => 'estandar'], ['name' => 'Estándar', 'features' => ['aula']]);
        $this->school->update(['plan' => 'estandar']);
        $this->teacher->givePermissionTo(Permission::findOrCreate('aula.ver_asignadas', 'web'));
        $aula = Aula::firstOrCreate(['ano_lectivo_id' => $this->year->id, 'grupo_id' => $this->group->id, 'materia_id' => $this->subject->id]);
        $period = \App\Models\Academico\Periodo::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'P1', 'orden' => 1,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'estado' => 'abierto']);
        $section = AulaSeccion::create(['aula_id' => $aula->id, 'periodo_id' => $period->id, 'titulo' => 'Actividades', 'visible_estudiantes' => true, 'autor_id' => $this->teacher->id]);
        return AulaRecurso::create(['seccion_id' => $section->id, 'tipo' => 'tarea', 'titulo' => 'Proyecto',
            'estado' => 'borrador', 'visible_estudiantes' => false, 'autor_id' => $this->teacher->id, 'zona_publicacion' => 'America/Bogota']);
    }

    public function test_event_calendar_uses_actual_publication_then_deadline_in_school_timezone(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(12, 0));
        $task = $this->calendarTask();
        $this->assertNull($task->publicado_en);
        $filters = ['desde' => '2026-10-01', 'hasta' => '2026-10-31'];
        $calendar = app(TaskCalendar::class);
        $this->assertSame(0, $calendar->list($this->teacher, $filters)->total());
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 02:00:00', 'UTC'));
        $task->update(['estado' => 'publicado', 'visible_estudiantes' => true]);
        $items = $calendar->list($this->teacher, $filters)->items();
        $this->assertSame('2026-10-09', $items[0]['fecha']);
        $this->assertSame('aula', $items[0]['origen']);
        $this->assertSame(0, Evento::count());
        $published = $task->publicado_en->toIso8601String();
        $this->travel(2)->days();
        $task->update(['titulo' => 'Proyecto actualizado', 'fecha_limite' => '2026-10-21 03:00:00']);
        $items = $calendar->list($this->teacher, $filters)->items();
        $this->assertSame('2026-10-20', $items[0]['fecha']);
        $this->assertSame($published, $items[0]['publicado_en']);
        $api = $this->eventApi($this->teacher);
        $this->getJson("$api/tareas?opaque=1&desde=2026-10-01&hasta=2026-10-31")->assertOk()
            ->assertJsonPath('data.0.url_token', OpaqueUrlToken::for('aula-recurso', $task->id))->assertJsonMissingPath('data.0.id');
        $task->delete();
        $this->assertSame(0, $calendar->list($this->teacher, $filters)->total());
        $this->travelBack();
    }

    public function test_event_calendar_respects_drafts_scheduling_visibility_student_enrollment_and_plan(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 12:00:00', 'UTC'));
        $task = $this->calendarTask();
        $student = $this->eventStudent('calendar@test.test');
        $student->givePermissionTo(Permission::findOrCreate('aula.ver_propias', 'web'));
        $filters = ['desde' => '2026-10-01', 'hasta' => '2026-10-31'];
        $calendar = app(TaskCalendar::class);
        $task->update(['estado' => 'programado', 'visible_estudiantes' => true, 'disponible_desde' => now()->addDay()]);
        $this->assertSame(0, $calendar->list($student, $filters)->total());
        $task->seccion->update(['visible_estudiantes' => false]);
        $this->assertNull($task->fresh()->publicado_en);
        $this->travel(3)->days();
        $task->seccion->update(['visible_estudiantes' => true]);
        $this->assertSame('2026-10-13', $calendar->list($student, $filters)->items()[0]['fecha']);
        $this->assertSame(0, $calendar->list($this->rector, $filters)->total(), 'Event manager alone is not Aula permission.');
        Matricula::where('estudiante_id', $student->id)->update(['estado' => 'retirada']);
        $this->assertSame(0, $calendar->list($student, $filters)->total());
        $task->update(['visible_estudiantes' => false]);
        $this->assertSame(0, $calendar->list($this->teacher, $filters)->total());
        $task->update(['visible_estudiantes' => true]);
        $task->update(['estado' => 'programado', 'disponible_desde' => null]);
        $this->assertSame(0, $calendar->list($this->teacher, $filters)->total());
        $task->update(['estado' => 'publicado']);
        $this->school->update(['plan' => 'esencial']);
        $this->assertSame(0, $calendar->list($this->teacher, $filters)->total());
        $this->travelBack();
    }

    public function test_event_mail_templates_escape_user_content_and_include_school_and_private_app_link(): void
    {
        foreach ([true, false] as $english) {
            $html = view('mail.event', ['english' => $english, 'event' => ['school' => 'Colegio de prueba', 'title' => '<script>evil</script>',
                'description' => 'Proyecto <img onerror="bad">', 'date' => '2026-10-20', 'time' => '10:00', 'zone' => 'America/Bogota',
                'url' => 'https://school.example/comunicacion/eventos?evento='.str_repeat('a', 24)]])->render();
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringNotContainsString('<img onerror', $html);
            $this->assertStringContainsString('Colegio de prueba', $html);
            $this->assertStringContainsString('/comunicacion/eventos?evento=', $html);
            $this->assertStringContainsString($english ? 'View event' : 'Ver evento', $html);
        }
    }

    public function test_event_workflow_thousand_students_are_durable_and_dispatched_on_a_separate_queue(): void
    {
        $this->eventMailReady();
        $password = bcrypt('SyntheticPassword123!');
        foreach (range(1, 1000) as $i) {
            $id = DB::table('users')->insertGetId(['name' => 'Synthetic student', 'email' => "large-$i@test.test",
                'password' => $password, 'role' => 'estudiante', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('matriculas')->insert(['estudiante_id' => $id, 'grupo_id' => $this->group->id,
                'ano_lectivo_id' => $this->year->id, 'estado' => 'activa', 'created_at' => now(), 'updated_at' => now()]);
        }
        $api = $this->eventApi($this->rector);
        $token = $this->postJson($api.'?opaque=1', $this->eventInput(['institucional' => true, 'grupo_tokens' => [], 'enviar_correo' => true]))->json('data.url_token');
        $this->postJson("$api/$token/publicar?opaque=1", ['version' => 1])->assertOk();
        $this->assertSame(1000, EventoCorreo::where('estado', 'pendiente')->count());
        Cache::flush();
        \Illuminate\Support\Facades\Queue::fake();
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('eventos:enviar-correos'));
        \Illuminate\Support\Facades\Queue::assertPushedOn('event-mail', \App\Jobs\SendEventMailBatch::class,
            fn ($job) => $job->school === $this->school->id && $job->connection === 'event_mail'
                && ! str_contains(serialize($job), '@test.test'));
        $this->mock(SchoolMail::class, fn ($mock) => $mock->shouldReceive('sendEvent')->times(10));
        (new \App\Jobs\SendEventMailBatch($this->school->id))->handle();
        $this->assertSame(10, EventoCorreo::where('estado', 'enviado')->count());
        $this->assertSame(990, EventoCorreo::where('estado', 'pendiente')->count());
    }

    public function test_event_mail_hides_timezone_label_but_keeps_date_and_time_in_both_formats(): void
    {
        $event = ['school' => 'Colegio de prueba', 'title' => 'Evento de prueba',
            'description' => 'Descripción', 'date' => '2026-10-20', 'time' => '10:00',
            'zone' => 'America/Bogota', 'url' => 'https://school.example/comunicacion/eventos'];
        foreach ([true, false] as $english) {
            foreach (['mail.event', 'mail.event-text'] as $template) {
                $body = view($template, compact('event', 'english'))->render();
                $this->assertStringNotContainsString('America/Bogota', $body);
                $this->assertStringContainsString('2026-10-20', $body);
                $this->assertStringContainsString('10:00', $body);
            }
        }
    }

    public function test_event_workflow_recovers_an_interrupted_batch_and_cancels_withdrawn_recipients(): void
    {
        $this->eventMailReady();
        $first = $this->eventStudent('interrupted@test.test');
        $second = $this->eventStudent('moved@test.test');
        $api = $this->eventApi($this->rector);
        $token = $this->postJson($api.'?opaque=1', $this->eventInput(['enviar_correo' => true]))->json('data.url_token');
        $this->postJson("$api/$token/publicar?opaque=1", ['version' => 1])->assertOk();
        DB::table('evento_correos')->where('estudiante_id', $first->id)->update(['estado' => 'enviando', 'updated_at' => now()->subMinutes(6)]);
        Matricula::where('estudiante_id', $second->id)->update(['estado' => 'retirada']);
        $this->mock(SchoolMail::class, fn ($mock) => $mock->shouldReceive('sendEvent')->once()->with($first->email, \Mockery::type('array'), 'es'));
        Cache::flush(); app(EventMailOutbox::class)->deliverBatch();
        $this->assertSame('enviado', EventoCorreo::where('estudiante_id', $first->id)->value('estado'));
        $this->assertSame('cancelado', EventoCorreo::where('estudiante_id', $second->id)->value('estado'));
    }

    public function test_event_workflow_delivery_records_do_not_block_user_retention_purge(): void
    {
        $this->eventMailReady();
        $student = $this->eventStudent('purged@test.test');
        $api = $this->eventApi($this->rector);
        $token = $this->postJson($api.'?opaque=1', $this->eventInput(['enviar_correo' => true]))->json('data.url_token');
        $this->postJson("$api/$token/publicar?opaque=1", ['version' => 1])->assertOk();
        $this->assertSame(1, EventoCorreo::count());
        $student->delete();
        // Remove the synthetic academic reference to isolate the outbox foreign key.
        Matricula::where('estudiante_id', $student->id)->delete();
        // No real student or academic record is touched by this isolated fixture.
        $student->forceDelete();
        $this->assertSame(0, EventoCorreo::count());
    }

    public function test_event_mail_log_and_failover_transports_cannot_be_reported_as_smtp_delivery(): void
    {
        foreach (['log', 'array', 'failover', 'roundrobin'] as $transport) {
            config(['mail.default' => $transport]);
            $this->assertFalse((new SchoolMail)->readyForEvents());
        }
        config(['mail.default' => 'smtp']);
        $this->assertTrue((new SchoolMail)->readyForEvents()); // Configuration only; no real network send.
    }

    public function test_event_mail_preview_uses_one_recipient_real_template_and_no_school_records(): void
    {
        $this->school->domains()->firstOrCreate(['domain' => 'academic-test.localhost']);
        $recipient = 'preview-only@example.test';
        $receipt = str_repeat('a', 24);
        $users = User::count();
        $events = Evento::count();
        Cache::put('event-mail-preview:'.$receipt, ['status' => 'queued']);
        $this->mock(SchoolMail::class, fn ($mock) => $mock->shouldReceive('sendEvent')->once()
            ->with($recipient, \Mockery::on(fn ($data) => str_starts_with($data['title'], '[PRUEBA]')
                && str_ends_with($data['url'], '/comunicacion/eventos') && $data['school'] === $this->school->name), 'es'));
        $job = new \App\Jobs\SendEventMailPreview($this->school->id, $recipient, $receipt);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, $job);
        $this->assertSame('event_mail', $job->connection);
        $this->assertSame('event-mail', $job->queue);
        $job->handle();
        $job->handle(); // Acknowledged preview must not be sent again.
        $this->assertSame('smtp_accepted', Cache::get('event-mail-preview:'.$receipt)['status']);
        $this->assertFalse(Cache::get('event-mail-preview:'.$receipt)['inbox_delivery_verified']);
        $this->assertSame($users, User::count());
        $this->assertSame($events, Evento::count());
        $this->assertSame(0, EventoCorreo::count());
    }

    public function test_event_mail_preview_does_not_claim_delivery_when_transport_fails(): void
    {
        $this->school->domains()->firstOrCreate(['domain' => 'academic-test.localhost']);
        $receipt = str_repeat('b', 24);
        $this->mock(SchoolMail::class, fn ($mock) => $mock->shouldReceive('sendEvent')->once()->andThrow(new \RuntimeException('synthetic')));
        $job = new \App\Jobs\SendEventMailPreview($this->school->id, 'preview-only@example.test', $receipt);
        try {
            $job->handle();
            $this->fail('Transport failure should be reported.');
        } catch (\RuntimeException $error) {
            $this->assertSame('event_mail_preview_failed', $error->getMessage());
        }
        $this->assertSame('failed', Cache::get('event-mail-preview:'.$receipt)['status']);
        $this->assertSame(1, $job->tries);
    }
}
