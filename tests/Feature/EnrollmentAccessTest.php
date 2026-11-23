<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Jobs\SendEnrollmentNotice;
use App\Models\AuditLog;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\Calificacion;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Nivel;
use App\Models\Academico\Materia;
use App\Models\Academico\Periodo;
use App\Models\Ingreso\Campana;
use App\Models\Ingreso\Notificacion;
use App\Models\Ingreso\Solicitud;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContactNumbers;
use App\Services\Ingreso\EnrollmentInput;
use App\Services\Ingreso\EnrollmentIntake;
use App\Services\Ingreso\EnrollmentMailDeliveryException;
use App\Services\Ingreso\EnrollmentSettings;
use App\Services\Ingreso\EnrollmentStorage;
use App\Services\Ingreso\EnrollmentSubmission;
use App\Services\Ingreso\IdentityDocumentReader;
use App\Services\SchoolMail;
use App\Services\TenantProvisioner;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\FileScanner;
use App\Support\Storage\NullScanner;
use App\Support\Storage\StorageService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\DatabaseConfig;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class EnrollmentAccessTest extends TestCase
{
    use \Tests\Support\EnrollmentTrajectoryTests;
    use \Tests\Support\CertifiedExternalGradeTests;

    private Tenant $school;

    private string $base = 'http://inscripciones.localhost/api';

    private string $staffToken;

    private string $campaign;

    private string $grade;

    protected function setUp(): void
    {
        parent::setUp();
        $database = config('database.connections.pgsql.database');
        if (config('database.default') !== 'pgsql' || ! preg_match('/^codex_sedes_test_[a-f0-9]{12}$/D', $database)) {
            throw new \RuntimeException('Use scripts/test-sedes-postgres.php --enrollment.');
        }
        $this->artisan('migrate:fresh', ['--database' => 'pgsql', '--force' => true])->assertExitCode(0);
        DatabaseConfig::generateDatabaseNamesUsing(fn ($tenant) => $database.'_t_'.substr(hash('sha256', $tenant->id), 0, 10));
        $this->seed(DatabaseSeeder::class);
        $this->withoutMiddleware(EnsureOnboardingComplete::class)->withCredentials();
        $this->school = app(TenantProvisioner::class)->provision(['name' => 'Inscripciones', 'slug' => 'inscripciones',
            'plan' => 'premium', 'rector_email' => 'rector@inscripciones.test'])['tenant'];
        $this->school->update(['status' => 'active']);
        $this->school->run(function () {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            $this->staffToken = $rector->createToken('test')->plainTextToken;
            $year = AnoLectivo::create(['nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
                'fecha_fin' => '2027-12-31', 'estado' => 'en_curso', 'num_periodos' => 4]);
            $level = Nivel::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Primaria', 'nivel_educativo' => 'primaria', 'estado' => 'activo']);
            $grade = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $level->id, 'nombre' => 'Primero', 'estado' => 'activo']);
            $this->grade = OpaqueUrlToken::for('grado', $grade->id);
            $campaign = Campana::create(['nombre' => 'Convocatoria compartida', 'ano_lectivo_id' => $year->id,
                'abierta' => true, 'desde' => today()->subDay(), 'hasta' => today()->addMonth(),
                'configuracion' => ['pin_manual' => true, 'pin_expira_en' => null, 'privacidad' => 'Tratamiento de datos de prueba.',
                    'grados' => [['token' => $this->grade, 'nombre' => 'Primero', 'cupo' => 30]], 'campos' => [], 'documentos' => []]]);
            $this->campaign = OpaqueUrlToken::for('ingreso-campana', $campaign->id);
        });
        $this->withHeader('Origin', 'http://inscripciones.localhost');
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        DB::setDefaultConnection(config('tenancy.database.central_connection'));
        parent::tearDown();
    }

    private function requestPin(): string
    {
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaign.'/iniciar', [
            'primer_nombre' => 'Ana', 'primer_apellido' => 'Pruebas', 'email' => 'ana@example.test',
            'tipo_documento' => 'TI', 'numero_documento' => '1234567',
        ])->assertAccepted()->assertJsonStructure(['radicado']);

        return OpaqueUrlToken::for('ingreso-solicitud', Solicitud::firstOrFail()->id);
    }


    private function enrollmentOperator(string $name, string $permission): User
    {
        $operator = User::create(['name' => 'Operador '.$name, 'email' => strtolower($name).'.staff@example.test',
            'role' => 'secretaria', 'status' => 'active', 'must_change_password' => false, 'password' => 'Testing123!']);
        $operator->assignRole('secretaria');
        $operator->givePermissionTo(['ingreso.ver', $permission]);
        return $operator;
    }

    private function enrollmentRequestWorker(User $operator, string $path, array $body): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Support/enrollment-request-worker.php')], base_path(),
            ['APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'null', 'BROADCAST_CONNECTION' => 'null',
                'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array']);
        $worker->setInput(json_encode(['session' => $operator->createToken('test')->plainTextToken,
            'path' => $path, 'body' => $body], JSON_THROW_ON_ERROR));
        return $worker;
    }

    /** @param list<Process> $workers */
    private function runCompetingEnrollmentRequests(AnoLectivo|Notificacion $record, array $workers): array
    {
        $connection = DB::connection();
        $observer = DB::connection('pgsql');
        $responses = [];
        try {
            $connection->transaction(function () use ($record, $connection, $observer, $workers) {
                $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                foreach ($workers as $worker) {
                    $worker->start();
                }
                // Release the row only once BOTH HTTP requests are inside their transactions.
                $deadline = microtime(true) + 20;
                do {
                    foreach ($workers as $worker) {
                        if (! $worker->isRunning()) {
                            $this->fail('An enrollment request exited before the lock was released. '.$worker->getErrorOutput().$worker->getOutput());
                        }
                    }
                    $waiting = (int) $observer->table('pg_stat_activity')->where('datname', $connection->getDatabaseName())
                        ->where('wait_event_type', 'Lock')->where('query', 'ilike', '%"'.$record->getTable().'"%for update%')->count();
                    if ($waiting === 2) {
                        return;
                    }
                    usleep(50_000);
                } while (microtime(true) < $deadline);
                $this->fail('PostgreSQL did not observe both enrollment requests waiting on the record lock.');
            });
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $responses[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
        } finally {
            foreach ($workers as $worker) {
                $worker->stop(1);
            }
        }
        return $responses;
    }

    public function test_two_operators_confirm_the_last_group_seat_at_the_same_time(): void
    {
        // Creating a new student also checks the school's subscription across campuses.
        app(\App\Services\SedeProvisioner::class)->provision($this->school, ['slug' => 'norte', 'name' => 'Norte']);
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        $year = AnoLectivo::sole();
        $grade = Grado::sole();
        $group = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'nombre' => '01A',
            'estado' => 'activo', 'cupo_maximo' => 2]);
        $existing = User::create(['name' => 'Estudiante matriculado', 'email' => 'actual@example.test',
            'role' => 'estudiante', 'status' => 'active', 'password' => 'Testing123!']);
        $occupied = Matricula::create(['estudiante_id' => $existing->id, 'ano_lectivo_id' => $year->id,
            'grupo_id' => $group->id, 'estado' => 'activa']);
        $operators = $applications = $pairs = $workers = [];
        $campaign = Campana::sole();
        foreach (['Ana', 'Luis'] as $index => $name) {
            $operator = $this->enrollmentOperator($name, 'ingreso.asignar');
            $operators[] = $operator;
            $application = Solicitud::create(['campana_id' => $campaign->id, 'grado_id' => $grade->id,
                'grado_aprobado_id' => $grade->id, 'estado' => 'aprobada', 'pin_manual' => true,
                'email' => strtolower($name).'@example.test', 'pin_hash' => Hash::make('12345678'),
                'sesion_version' => Str::random(32), 'validada_en' => now(), 'email_verificado' => now(),
                'datos' => ['primer_nombre' => $name, 'primer_apellido' => 'Pruebas', 'tipo_documento' => 'TI',
                    'numero_documento' => '1234567'.$index]]);
            $applications[] = $application;
            $pairs[] = ['solicitud_token' => OpaqueUrlToken::for('ingreso-solicitud', $application->id),
                'grupo_token' => OpaqueUrlToken::for('grupo', $group->id)];
            $workers[] = $this->enrollmentRequestWorker($operator, 'campanas/'.$this->campaign.'/distribuir',
                ['confirmar' => true, 'asignaciones' => [$pairs[$index]]]);
        }

        $endpoint = $this->base.'/ingreso/campanas/'.$this->campaign.'/distribuir';
        Queue::fake();
        $this->postJson($endpoint, ['confirmar' => true, 'asignaciones' => $pairs])->assertUnprocessable();
        $this->assertSame(2, Solicitud::where('estado', 'aprobada')->whereNull('matricula_id')->whereNull('estudiante_id')->count());
        $this->assertSame(1, Matricula::where('grupo_id', $group->id)->count());
        $this->assertFalse(User::whereIn('email', [$applications[0]->email, $applications[1]->email])->exists());
        $this->assertFalse(Notificacion::exists());
        $this->assertFalse(DB::table('ingreso_historial')->where('evento', 'matriculada')->exists());

        $responses = $this->runCompetingEnrollmentRequests($year, $workers);
        $this->assertEqualsCanonicalizing([200, 422], array_column($responses, 'status'), json_encode($responses));
        $winner = array_search(200, array_column($responses, 'status'), true);
        $loser = 1 - $winner;
        $this->assertSame($pairs[$winner]['solicitud_token'], $responses[$winner]['assignments'][0]['solicitud_token']);
        $this->assertStringContainsString('pendiente de grupo', $responses[$loser]['message']);
        $this->postJson($endpoint, ['confirmar' => true, 'asignaciones' => [$pairs[$winner]]])->assertOk();
        $this->postJson($endpoint, ['confirmar' => true, 'asignaciones' => [$pairs[$loser]]])->assertUnprocessable();
        $this->postJson($endpoint, ['confirmar' => false])->assertOk()->assertJsonPath('sin_cupo', 1)->assertJsonCount(0, 'asignaciones');
        $file = $this->base.'/ingreso/solicitudes/';
        $this->getJson($file.$pairs[$winner]['solicitud_token'])->assertOk()
            ->assertJsonPath('data.estado', 'matriculada')->assertJsonPath('data.grupo', '01A');
        $this->getJson($file.$pairs[$loser]['solicitud_token'])->assertOk()
            ->assertJsonPath('data.estado', 'aprobada')->assertJsonPath('data.grupo', null);
        $admitted = $applications[$winner]->fresh();
        $pending = $applications[$loser]->fresh();
        $this->assertSame('matriculada', $admitted->estado);
        $this->assertSame($admitted->estudiante_id, Matricula::findOrFail($admitted->matricula_id)->estudiante_id);
        $this->assertEqualsCanonicalizing([$occupied->id, $admitted->matricula_id],
            Matricula::where('grupo_id', $group->id)->where('estado', 'activa')->pluck('id')->all());
        $this->assertSame('aprobada', $pending->estado);
        $this->assertSame($grade->id, $pending->grado_aprobado_id);
        $this->assertNull($pending->matricula_id);
        $this->assertNull($pending->estudiante_id);
        $this->assertFalse(User::where('email', $pending->email)->exists());
        $event = DB::table('ingreso_historial')->where('evento', 'matriculada')->sole();
        $this->assertSame($admitted->id, $event->solicitud_id);
        $this->assertSame($operators[$winner]->id, $event->actor_id);
        $this->assertEqualsCanonicalizing([$admitted->id.':credenciales', $admitted->id.':grupo'], Notificacion::pluck('clave')->all());
    }

    private function validatedApplicant(Campana $campaign, Grado $grade, string $name): Solicitud
    {
        return Solicitud::create(['campana_id' => $campaign->id, 'grado_id' => $grade->id,
            'estado' => 'validada', 'pin_manual' => true, 'email' => strtolower($name).'@example.test',
            'pin_hash' => Hash::make('12345678'), 'sesion_version' => Str::random(32),
            'email_verificado' => now(), 'consentimiento_en' => now(), 'enviada_en' => now(), 'validada_en' => now(),
            'datos' => ['primer_nombre' => $name, 'primer_apellido' => 'Pruebas',
                'nacimiento' => today()->subYears(7)->toDateString()]]);
    }

    public function test_two_operators_approve_ana_once_using_one_grade_seat_and_one_notice(): void
    {
        $this->withToken($this->staffToken)->withHeader('Accept-Language', 'es')
            ->getJson($this->base.'/matriculas')->assertOk();
        $year = AnoLectivo::sole();
        $grade = Grado::sole();
        $campaign = Campana::sole();
        $config = $campaign->configuracion;
        $config['grados'][0]['cupo'] = 2;
        $campaign->update(['configuracion' => $config]);

        $decisionPath = fn (Solicitud $s) => 'solicitudes/'.OpaqueUrlToken::for('ingreso-solicitud', $s->id).'/decision';
        $api = $this->base.'/ingreso/';
        $ana = $this->validatedApplicant($campaign, $grade, 'Ana');
        $decision = ['estado' => 'aprobada', 'grado_token' => $this->grade];
        $first = $this->enrollmentOperator('Andrea', 'ingreso.decidir');
        $second = $this->enrollmentOperator('Sara', 'ingreso.decidir');
        $path = $decisionPath($ana);
        $responses = $this->runCompetingEnrollmentRequests($year, [
            $this->enrollmentRequestWorker($first, $path, $decision),
            $this->enrollmentRequestWorker($second, $path, $decision),
        ]);
        foreach ($responses as $response) {
            $this->assertSame(200, $response['status'], $response['message'] ?? '');
            $this->assertSame('aprobada', $response['state']);
            $this->assertSame(OpaqueUrlToken::for('ingreso-solicitud', $ana->id), $response['application']);
        }

        Queue::fake();
        $this->postJson($api.$path, $decision)->assertOk()->assertJsonPath('data.estado', 'aprobada')
            ->assertJsonPath('data.grado_aprobado_token', $this->grade)->assertJsonPath('data.grupo', null)
            ->assertJsonCount(1, 'data.historial')->assertJsonPath('data.historial.0.evento', 'aprobada');
        Queue::assertNotPushed(SendEnrollmentNotice::class);
        $history = DB::table('ingreso_historial')->where('solicitud_id', $ana->id)->where('evento', 'aprobada')->sole();
        $audit = AuditLog::where('recurso', 'ingreso-solicitud')->where('recurso_id', (string) $ana->id)
            ->where('valor_nuevo->evento', 'aprobada')->sole();
        $admittingOperator = $responses[0]['notices'] === [] ? $second : $first;
        $this->assertSame($admittingOperator->id, $history->actor_id);
        $this->assertSame($history->actor_id, $audit->actor_id);
        $notice = Notificacion::sole();
        $this->assertSame($ana->email, $notice->email);
        $this->assertSame('Tu inscripción fue admitida', $notice->asunto);
        $this->assertSame([$notice->id], array_merge(...array_column($responses, 'notices')));
        $this->assertSame(0, Matricula::count());

        // With two places, exactly one further applicant may be admitted.
        $luis = $this->validatedApplicant($campaign, $grade, 'Luis');
        $eva = $this->validatedApplicant($campaign, $grade, 'Eva');
        $this->postJson($api.$decisionPath($luis), $decision)->assertOk()->assertJsonPath('data.estado', 'aprobada');
        $this->postJson($api.$decisionPath($eva), $decision)->assertUnprocessable()
            ->assertJsonPath('message', 'No quedan cupos en el grado. Usa la lista de espera.');
        $this->assertEqualsCanonicalizing([$ana->id, $luis->id], Solicitud::where('estado', 'aprobada')
            ->where('grado_aprobado_id', $grade->id)->whereNull('matricula_id')->pluck('id')->all());
        $this->assertSame('validada', $eva->fresh()->estado);
        $this->assertSame(1, Notificacion::where('email', $ana->email)->count());
        $this->assertFalse(Notificacion::where('email', $eva->email)->exists());
    }

    public function test_enrollment_notice_survives_repeated_quota_delays_then_sends_once(): void
    {
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        config(['queue.connections.spike' => [...config('queue.connections.database'), 'connection' => 'pgsql', 'queue' => 'spike']]);
        $notice = Notificacion::create(['clave' => 'prueba:admision', 'email' => 'ana@example.test',
            'asunto' => 'Admisión', 'contenido' => 'Inscripción admitida.']);
        $mail = $this->mock(SchoolMail::class);
        $mail->shouldReceive('sendEnrollment')->times(6)->ordered()->andThrow(new \App\Services\SchoolMailDeferred(60));
        $mail->shouldReceive('sendEnrollment')->once()->ordered();
        $enqueue = fn () => Queue::connection('spike')->push(new SendEnrollmentNotice((string) $this->school->id, $notice->id));
        $work = fn () => $this->artisan('queue:work', ['connection' => 'spike', '--queue' => 'spike', '--once' => true, '--sleep' => 0])->assertExitCode(0);
        $jobs = DB::connection('pgsql')->table('jobs')->where('queue', 'spike');
        $enqueue();
        for ($i = 0; $i < 6; $i++) {
            $work();
            $this->assertNull($notice->refresh()->enviada_en);
            $this->assertGreaterThan(now()->timestamp, $jobs->sole()->available_at);
            $this->travel(61)->seconds();
        }
        $work();
        $sentAt = $notice->refresh()->enviada_en;
        $this->assertNotNull($sentAt);
        $enqueue();
        $work();
        $this->assertTrue($sentAt->equalTo($notice->refresh()->enviada_en));
        $this->assertSame(0, $jobs->count());
    }

    public function test_admission_email_recovers_after_smtp_failure_without_another_seat_or_delivery(): void
    {
        $this->withToken($this->staffToken)->withHeader('Accept-Language', 'es')
            ->getJson($this->base.'/matriculas')->assertOk();
        $campaign = Campana::sole();
        $grade = Grado::sole();
        $config = $campaign->configuracion;
        $config['grados'][0]['cupo'] = 1;
        $campaign->update(['configuracion' => $config]);
        $ana = $this->validatedApplicant($campaign, $grade, 'Ana');
        $luis = $this->validatedApplicant($campaign, $grade, 'Luis');
        $decisionPath = fn (Solicitud $s) => $this->base.'/ingreso/solicitudes/'.OpaqueUrlToken::for('ingreso-solicitud', $s->id).'/decision';
        $decision = ['estado' => 'aprobada', 'grado_token' => $this->grade];

        $smtp = new Process([PHP_BINARY, base_path('tests/Support/admission-smtp-mailbox.php'), '--reject-first']);
        $smtp->setTimeout(15);
        $smtp->start();
        try {
            $this->assertTrue($smtp->waitUntil(fn ($type, $output) => str_contains($output, 'ready 127.0.0.1:')));
            $this->assertSame(1, preg_match('/ready 127\.0\.0\.1:(\d+)/', $smtp->getOutput(), $address));
            $this->assertNull(app(SchoolMail::class)->settings());
            config(['mail.default' => 'admission-test', 'mail.from.address' => 'colegio@example.test',
                'mail.mailers.admission-test' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => (int) $address[1], 'timeout' => 3],
                'queue.default' => 'admission-test', 'queue.connections.admission-test' => [
                    ...config('queue.connections.database'), 'connection' => 'pgsql', 'queue' => 'admission-test'],
                'performance.realtime_queue' => 'broadcast-test']);
            $jobs = DB::connection('pgsql')->table('jobs')->where('queue', 'admission-test');
            $work = fn () => $this->artisan('queue:work', ['connection' => 'admission-test', '--queue' => 'admission-test',
                '--once' => true, '--sleep' => 0])->assertExitCode(0);
            $recover = fn () => $this->artisan('ingreso:reenviar-notificaciones', ['--tenant' => $this->school->slug])->assertExitCode(0);

            $this->postJson($decisionPath($ana), $decision)->assertOk()->assertJsonPath('data.estado', 'aprobada');
            $this->assertSame(1, $jobs->count());
            $notice = Notificacion::sole();
            $this->assertSame('Tu inscripción fue admitida', $notice->asunto);
            $work();
            $this->assertStringContainsString("deferred\n", $smtp->getOutput());
            $this->assertStringNotContainsString('delivered ', $smtp->getOutput());
            $this->assertNull($notice->refresh()->enviada_en);
            $this->assertNotNull($notice->contenido);
            $this->getJson($this->base.'/ingreso/notificaciones')->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.enviada_en', null);
            $this->assertSame($grade->id, $ana->refresh()->grado_aprobado_id);
            $this->assertSame('aprobada', $ana->estado);
            $this->postJson($decisionPath($luis), $decision)->assertUnprocessable()
                ->assertJsonPath('message', 'No quedan cupos en el grado. Usa la lista de espera.');
            $retry = $jobs->sole();
            $this->assertSame(1, $retry->attempts);
            $this->assertGreaterThan(now()->timestamp, $retry->available_at);

            // The automatic retry becomes due while an operator also requeues the pending notice.
            $this->travelTo(now()->setTimestamp($retry->available_at + 1));
            $recover();
            $this->assertSame(2, $jobs->count());
            $work();
            $sentAt = $notice->refresh()->enviada_en;
            $this->assertNotNull($sentAt);
            $this->assertNull($notice->contenido);
            $work();
            $recover();
            $this->assertSame(0, $jobs->count());
            $this->assertTrue($sentAt->equalTo($notice->refresh()->enviada_en));
            $this->assertSame(1, preg_match_all('/^delivered (.+)$/m', $smtp->getOutput(), $delivered));
            $message = base64_decode(trim($delivered[1][0]), true);
            $this->assertStringContainsString('To: '.$ana->email, $message);
            $this->assertStringContainsString('Tu inscripción fue admitida', quoted_printable_decode($message));
            $this->assertSame($ana->id, Solicitud::where('grado_aprobado_id', $grade->id)->where('estado', 'aprobada')->sole()->id);
            $this->assertSame('validada', $luis->refresh()->estado);
            $this->assertNull($ana->refresh()->matricula_id);
            $this->assertSame(1, DB::table('ingreso_historial')->where('solicitud_id', $ana->id)->where('evento', 'aprobada')->count());
            $this->getJson($this->base.'/ingreso/notificaciones')->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.enviada_en', $sentAt->toIso8601String());
        } finally {
            Mail::purge('admission-test');
            $smtp->stop(0);
            $this->travelBack();
        }
    }

    public function test_legacy_enrollment_mail_without_acknowledgment_requires_review_in_its_own_school(): void
    {
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        $ana = $this->validatedApplicant(Campana::sole(), Grado::sole(), 'Ana');
        $migration = require database_path('migrations/tenant/2026_11_23_000005_track_enrollment_mail_review.php');
        $migration->down();
        foreach (['grupo', 'admision:anterior', 'pin:anterior'] as $key) {
            DB::table('ingreso_notificaciones')->insert(['clave' => $ana->id.':'.$key, 'email' => $ana->email,
                'asunto' => 'Aviso anterior', 'contenido' => $key === 'grupo' ? null : encrypt('Aviso pendiente.', false),
                'enviada_en' => $key === 'grupo' ? now() : null,
                'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        }
        $migration->up();
        $token = OpaqueUrlToken::for('ingreso-solicitud', $ana->id);
        $endpoint = $this->base.'/ingreso/solicitudes/'.$token.'/revision-correos';
        $item = $this->getJson($endpoint)->assertOk()->assertJsonCount(1, 'pendientes')
            ->assertJsonPath('pendientes.0.intento', 0)->assertJsonPath('pendientes.0.tipo', 'ingreso.admision')->json('pendientes.0');
        $id = Notificacion::where('tipo', 'admision')->sole()->id;
        $this->assertSame(OpaqueUrlToken::for('ingreso-aviso', $id), $item['selector']);
        $this->assertDatabaseHas('ingreso_notificaciones', ['tipo' => 'grupo', 'estado' => 'enviado']);
        $this->assertDatabaseHas('ingreso_notificaciones', ['tipo' => null, 'estado' => 'pendiente']);
        $other = app(TenantProvisioner::class)->provision(['name' => 'Otro colegio', 'slug' => 'otro-colegio',
            'plan' => 'premium', 'rector_email' => 'rector@otro.test'])['tenant'];
        [$foreignFile, $foreignNotice] = $other->run(fn () => [
            OpaqueUrlToken::for('ingreso-solicitud', $ana->id), OpaqueUrlToken::for('ingreso-aviso', $id)]);
        $body = ['intento' => 0, 'decision' => 'confirmar', 'motivo' => 'Recepción comprobada por Ana.'];
        $this->getJson(str_replace($token, $foreignFile, $endpoint))->assertNotFound();
        $this->postJson($endpoint.'/'.$foreignNotice, $body)->assertNotFound();
        $this->postJson($endpoint.'/'.$item['selector'], $body)->assertOk();
        $this->assertDatabaseHas('ingreso_notificaciones', ['id' => $id, 'estado' => 'confirmado']);
    }

    public function test_lost_enrollment_smtp_acknowledgment_is_excluded_from_ordinary_recovery(): void
    {
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        Queue::fake();
        $ana = $this->validatedApplicant(Campana::sole(), Grado::sole(), 'Ana');
        app(EnrollmentIntake::class)->notify($ana, 'admision:prueba', 'Aviso de admisión', 'Inscripción admitida.');
        $notice = Notificacion::sole();
        $smtp = new Process([PHP_BINARY, base_path('tests/Support/admission-smtp-mailbox.php'), '--drop-ack-once']);
        $smtp->setTimeout(30)->start();
        try {
            $this->assertTrue($smtp->waitUntil(fn ($type, $out) => str_contains($out, 'ready 127.0.0.1:')));
            preg_match('/ready 127\.0\.0\.1:(\d+)/', $smtp->getOutput(), $port);
            $this->assertNull(app(SchoolMail::class)->settings());
            config(['mail.default' => 'lost-reply', 'mail.from.address' => 'school@example.test',
                'mail.mailers.lost-reply' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => (int) $port[1], 'timeout' => 2]]);
            $job = Queue::pushed(SendEnrollmentNotice::class)->sole();
            $job->handle();
            $this->assertSame('en_revision', $notice->refresh()->estado);
            $this->assertNull($notice->enviada_en);
            Queue::fake();
            $this->artisan('ingreso:reenviar-notificaciones', ['--tenant' => $this->school->slug])->assertExitCode(0);
            $this->artisan('ingreso:procesar-revisiones', ['--tenant' => $this->school->slug])->assertExitCode(0);
            Queue::assertNotPushed(SendEnrollmentNotice::class);
            $job->handle();
            $this->assertSame(1, substr_count($smtp->getOutput(), 'delivered '));
        } finally {
            Mail::purge('lost-reply');
            $smtp->stop(1);
        }
    }

    public function test_manual_review_of_anas_three_enrollment_emails(): void
    {
        $this->withToken($this->staffToken)->withHeader('Accept-Language', 'es')->getJson($this->base.'/matriculas')->assertOk();
        $manager = Queue::getFacadeRoot();
        Queue::fake();
        $grade = Grado::sole();
        $campaign = Campana::sole();
        $ana = $this->validatedApplicant($campaign, $grade, 'Ana');
        $ana->update(['estado' => 'enviada', 'validada_en' => null,
            'datos' => [...$ana->datos, 'tipo_documento' => 'TI', 'numero_documento' => '12345678']]);
        $group = Grupo::create(['ano_lectivo_id' => $campaign->ano_lectivo_id, 'grado_id' => $grade->id, 'nombre' => '01A', 'estado' => 'activo']);
        $token = OpaqueUrlToken::for('ingreso-solicitud', $ana->id);
        $path = 'solicitudes/'.$token;
        $api = $this->base.'/ingreso/';
        $review = $path.'/revision-correos';
        $this->postJson($api.$path.'/validar')->assertOk();
        $this->postJson($api.$path.'/decision', ['estado' => 'aprobada', 'grado_token' => $this->grade])->assertOk();
        $this->postJson($api.'campanas/'.$this->campaign.'/distribuir', ['confirmar' => true,
            'asignaciones' => [['solicitud_token' => $token, 'grupo_token' => OpaqueUrlToken::for('grupo', $group->id)]]])->assertOk();
        $notices = Notificacion::where('solicitud_id', $ana->id)->get()->keyBy('tipo');
        $this->assertEqualsCanonicalizing(['validacion', 'admision', 'grupo'], $notices->keys()->all());
        $jobs = Queue::pushed(SendEnrollmentNotice::class)->keyBy('notice');
        Queue::swap($manager);
        config(['queue.default' => 'enrollment-review', 'queue.connections.enrollment-review' => [
            ...config('queue.connections.database'), 'connection' => 'pgsql', 'queue' => 'review']]);
        $smtp = new Process([PHP_BINARY, base_path('tests/Support/admission-smtp-mailbox.php')]);
        $smtp->setTimeout(90)->start();
        try {
            $this->assertTrue($smtp->waitUntil(fn ($type, $out) => str_contains($out, 'ready 127.0.0.1:')));
            preg_match('/ready 127\.0\.0\.1:(\d+)/', $smtp->getOutput(), $port);
            $this->assertNull(app(SchoolMail::class)->settings());
            $work = function (string $queue, string $mode) use ($port) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/mixed-mail-worker.php'), $mode, $port[1],
                    (string) now()->timestamp, 'enrollment', $queue], base_path());
                $this->assertSame($mode === '--deliver' ? 0 : 75, $process->setTimeout(20)->run(), $process->getErrorOutput().$process->getOutput());
            };
            $sweep = fn () => $this->artisan('ingreso:procesar-revisiones', ['--tenant' => $this->school->slug])->assertExitCode(0);
            foreach ($notices as $type => $notice) {
                Queue::connection()->push($jobs[$notice->id], '', $type);
                $work($type, $type === 'validacion' ? '--crash-after-acceptance' : '--crash-before-smtp');
            }
            $this->assertSame(1, substr_count($smtp->getOutput(), 'delivered '));
            $this->travel(6)->minutes();
            $sweep();
            $items = collect($this->getJson($api.$review)->assertOk()->assertJsonCount(3, 'pendientes')->json('pendientes'))->keyBy('tipo');
            $this->assertSame(['selector', 'destinatario', 'tipo', 'intento', 'fecha', 'puede_reenviar'],
                array_keys($items->first()));
            $urls = [];
            foreach ($notices as $type => $notice) {
                $item = $items['ingreso.'.$type];
                $this->assertSame('ana@example.test', $item['destinatario']);
                $this->assertTrue($item['puede_reenviar']);
                $urls[$type] = $review.'/'.$item['selector'];
                // Replay the reserved original job after its lease expires. It must not send.
                $work($type, '--deliver');
            }
            $this->assertSame(1, substr_count($smtp->getOutput(), 'delivered '));
            $confirm = ['intento' => 1, 'decision' => 'confirmar', 'motivo' => 'Ana encontró el aviso en su buzón.'];
            $resend = ['intento' => 1, 'decision' => 'reenviar', 'motivo' => 'Ana revisó el buzón y confirmó que falta el aviso.'];
            $reader = $this->enrollmentOperator('Consulta', 'ingreso.ver');
            $this->withToken($reader->createToken('test')->plainTextToken);
            app('auth')->forgetGuards();
            $this->getJson($api.$review)->assertForbidden();
            $this->postJson($api.$urls['validacion'], $confirm)->assertForbidden();
            $this->withToken($this->staffToken);
            app('auth')->forgetGuards();
            $this->postJson($api.$urls['validacion'], [...$confirm, 'motivo' => ' '])->assertUnprocessable();
            $this->postJson($api.$review.'/1', $resend)->assertNotFound();
            $other = $this->validatedApplicant($campaign, $grade, 'Luis');
            $this->postJson($api.str_replace($token, OpaqueUrlToken::for('ingreso-solicitud', $other->id), $urls['admision']), $resend)->assertNotFound();
            $this->postJson($api.$urls['validacion'], $confirm)->assertOk();
            $this->postJson($api.$urls['validacion'], $resend)->assertConflict();
            $operators = [];
            foreach (['Andrea', 'Sara'] as $name) {
                $operators[] = $this->enrollmentRequestWorker($this->enrollmentOperator($name, 'ingreso.correos.revisar'), $urls['admision'], $resend);
            }
            $responses = $this->runCompetingEnrollmentRequests($notices['admision'], $operators);
            $this->assertEqualsCanonicalizing([200, 409], array_column($responses, 'status'));
            $sweep();
            $sweep();
            $this->assertSame(1, DB::connection('pgsql')->table('jobs')->where('queue', 'review')->count());
            $work('review', '--deliver');
            $this->postJson($api.$urls['grupo'], $resend)->assertOk();
            $sweep();
            $work('review', '--crash-after-acceptance');
            $this->travel(6)->minutes();
            $sweep();
            $this->getJson($api.$review)->assertOk()->assertJsonCount(1, 'pendientes')
                ->assertJsonPath('pendientes.0.puede_reenviar', false)->assertJsonPath('pendientes.0.intento', 2);
            $this->postJson($api.$urls['grupo'], [...$resend, 'intento' => 2])->assertConflict();
            $this->postJson($api.$urls['grupo'], $confirm)->assertConflict();
            $this->postJson($api.$urls['grupo'], [...$confirm, 'intento' => 2])->assertOk();
            $work('review', '--deliver');
            $sweep();
            $this->assertSame(0, DB::connection('pgsql')->table('jobs')->where('queue', 'review')->count());
            $audit = $this->getJson($api.$review)->assertOk()->assertJsonCount(0, 'pendientes')->assertJsonCount(4, 'historial')->json('historial');
            foreach ($audit as $entry) {
                $this->assertNotEmpty($entry['responsable']);
                $this->assertNotEmpty($entry['fecha']);
                $this->assertSame($entry['decision'] === 'confirmar' ? $confirm['motivo'] : $resend['motivo'], $entry['motivo']);
            }
            $this->assertEqualsCanonicalizing(['ingreso.validacion', 'ingreso.admision', 'ingreso.grupo', 'ingreso.grupo'], array_column($audit, 'tipo_aviso'));
            $this->assertSame(4, AuditLog::where('recurso', 'ingreso.correo')->count());
            $this->assertSame(0, Notificacion::where('solicitud_id', $ana->id)->whereNotNull('contenido')->count());
            $this->assertSame('matriculada', $ana->refresh()->estado);
            $this->assertSame($group->id, Matricula::sole()->grupo_id);
            $this->assertSame(3, preg_match_all('/^delivered (.+)$/m', $smtp->getOutput(), $receipts));
            $subjects = [];
            foreach ($receipts[1] as $receipt) {
                $mime = base64_decode(trim($receipt), true);
                $this->assertStringContainsString('To: ana@example.test', $mime);
                preg_match('/^Subject: (.+(?:\r\n[ \t].+)*)/m', $mime, $subject);
                $subjects[] = mb_decode_mimeheader(trim($subject[1]));
            }
            $this->assertEqualsCanonicalizing($notices->pluck('asunto')->all(), $subjects);
        } finally {
            $smtp->stop(1);
            $this->travelBack();
        }
    }

    public function test_year_and_campaign_copy_preserve_anas_original_enrollment_file(): void
    {
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        Queue::fake();
        Storage::fake('tenant');
        $this->app->instance(FileScanner::class, new NullScanner);
        $year = AnoLectivo::sole();
        $campaign = Campana::sole();
        $grade = Grado::sole();
        DB::table('ingreso_configuracion')->where('id', 1)->update(['version' => 1, 'privacidad' => 'Aviso institucional vigente para inscripciones.']);
        $identity = ['key' => 'identidad', 'nombre' => 'Identidad', 'instrucciones' => 'Adjunta el documento.',
            'obligatorio' => true, 'formatos' => ['png'], 'grados' => []];
        $campaign->update(['configuracion' => [...$campaign->configuracion, 'documentos' => [$identity], 'documento_identidad' => 'identidad',
            'campos' => [['key' => 'procedencia', 'nombre' => 'Colegio anterior', 'tipo' => 'texto', 'obligatorio' => false]]]]);
        $group = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'nombre' => 'A', 'estado' => 'activo']);
        $ana = $this->validatedApplicant($campaign, $grade, 'Ana');
        $student = User::create(['name' => 'Ana Pruebas', 'email' => $ana->email, 'role' => 'estudiante', 'status' => 'active', 'password' => 'Testing123!']);
        $enrollment = Matricula::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $group->id, 'estudiante_id' => $student->id, 'estado' => 'activa']);
        $ana->update(['estado' => 'matriculada', 'estudiante_id' => $student->id, 'matricula_id' => $enrollment->id,
            'grado_aprobado_id' => $grade->id, 'requisitos_snapshot' => $campaign->configuracion]);
        $rector = User::where('role', 'rector')->firstOrFail();
        app(EnrollmentIntake::class)->event($ana, 'matriculada', 'Confirmación de Primero A', $rector);
        $file = app(StorageService::class)->store(UploadedFile::fake()->image('identidad-ana.png'), 'matriculas');
        $doc = $ana->documentos()->create(['archivo_id' => $file->id, 'requisito' => 'identidad', 'version' => 1,
            'nombre' => $file->original_name, 'estado' => 'aprobado', 'revisor_id' => $rector->id]);
        app(EnrollmentStorage::class)->organize($ana->id);
        $file->refresh();
        $period = Periodo::create(['ano_lectivo_id' => $year->id, 'orden' => 1, 'nombre' => 'Primer periodo',
            'fecha_inicio' => '2027-01-01', 'fecha_fin' => '2027-03-31', 'estado' => 'cerrado']);
        $area = Area::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Matemáticas', 'estado' => 'activo']);
        $math = Materia::create(['ano_lectivo_id' => $year->id, 'area_id' => $area->id, 'nombre' => 'Matemáticas', 'intensidad_horaria' => 4, 'estado' => 'activo']);
        [$mark] = $this->recordClassForTrajectory($enrollment, $group, $period, $math, $rector, CarbonImmutable::parse('2027-02-01'));
        $year->update(['estado' => 'cerrado']);
        $applications = $this->base.'/ingreso/solicitudes/';
        $original = $applications.OpaqueUrlToken::for('ingreso-solicitud', $ana->id);
        $before = $this->getJson($original)->assertOk()->assertJsonPath('data.historial.0.evento', 'matriculada')->json('data');

        $targetData = ['nombre' => '2028', 'fecha_inicio' => '2028-01-01', 'fecha_fin' => '2028-12-31', 'tipo_calendario' => 'A', 'num_periodos' => 4];
        $duplicate = $this->base.'/anos-lectivos/'.OpaqueUrlToken::for('ano-lectivo', $year->id).'/duplicar?opaque=1';
        $this->postJson($duplicate, $targetData + ['opciones' => ['solicitudes' => true]])->assertUnprocessable();
        $newYear = $this->postJson($duplicate, $targetData + ['opciones' => ['niveles' => true, 'grados' => true]])
            ->assertCreated()->assertJsonPath('data.estado', 'planificado')->json('data.url_token');
        $catalog = $this->getJson($this->base.'/ingreso/catalogo')->assertOk()->assertJsonPath('campanas.0.ano_nombre', '2027');
        $newGrade = collect($catalog->json('grados'))->where('ano_token', $newYear)->sole();
        $this->assertSame('Primero', $newGrade['nombre']);
        $this->assertSame(OpaqueUrlToken::for('ingreso-grado', $grade->ingreso_grado_id), $newGrade['regla_token']);
        $this->assertNotSame($this->grade, $newGrade['token']);
        $target = AnoLectivo::where('nombre', '2028')->sole();
        $this->assertSame(1, Nivel::where('ano_lectivo_id', $target->id)->count());
        foreach ([Grupo::class, Periodo::class, Area::class, Materia::class, Matricula::class] as $model) {
            $this->assertFalse($model::where('ano_lectivo_id', $target->id)->exists(), $model);
        }

        $rounds = $this->base.'/ingreso/campanas';
        $body = ['nombre' => 'Inscripción 2028', 'ano_token' => $newYear, 'abierta' => false, 'desde' => '2027-11-01', 'hasta' => null,
            'configuracion' => ['grados' => [['token' => $this->grade, 'nombre' => 'Primero', 'cupo' => 30]],
                'documentos' => [$identity], 'documento_identidad' => 'identidad', 'campos' => [], 'pin_expira_en' => null]];
        $this->postJson($rounds, $body)->assertNotFound();
        $body['configuracion']['grados'][0]['token'] = $newGrade['token'];
        $copy = $this->postJson($rounds, $body)->assertOk()->assertJsonPath('data.ano_token', $newYear)
            ->assertJsonPath('data.abierta', false)->assertJsonPath('data.configuracion.privacidad', 'Aviso institucional vigente para inscripciones.')
            ->assertJsonCount(0, 'data.configuracion.campos')
            ->assertJsonPath('data.configuracion.documentos.0.key', 'identidad')->assertJsonPath('data.configuracion.grados.0.cupo', 30)->json('data');
        $this->assertNotSame($before['campana']['enlace'], $copy['enlace']);
        $this->getJson($rounds.'/'.$copy['url_token'].'/solicitudes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($rounds.'/'.$this->campaign.'/solicitudes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.url_token', $before['url_token']);
        $this->assertSame($before, $this->getJson($original)->assertOk()->json('data'));
        $download = $this->get($original.'/documentos/'.OpaqueUrlToken::for('ingreso-documento', $doc->id))->assertOk()->streamedContent();
        $this->assertSame($file->checksum, hash('sha256', $download));
        $this->assertSame([$file->path], Storage::disk('tenant')->allFiles());
        $this->assertSame($mark->id, Calificacion::sole()->id);
        $this->assertEquals(4, $mark->refresh()->valor);
        $this->assertSame($enrollment->id, $mark->matricula_id);
        $this->getJson($this->base.'/matriculas/'.OpaqueUrlToken::for('matricula', $enrollment->id))->assertOk()
            ->assertJsonPath('data.trayectoria.resumen.notas', 1)->assertJsonPath('data.trayectoria.resumen.asistencias', 1);
    }

    public function test_ana_renews_from_her_account_and_class_access_requires_both_year_and_group(): void
    {
        $this->withHeader('Accept-Language', 'es');
        $this->school->run(function () {
            $campaign = Campana::firstOrFail();
            $campaign->update(['configuracion' => [...$campaign->configuracion, 'documento_identidad' => 'identidad',
                'verificar_identidad_automaticamente' => false, 'documentos' => [
                    ['key' => 'identidad', 'nombre' => 'Identidad', 'formatos' => ['png'], 'obligatorio' => true, 'grados' => []],
                ]]]);
        });
        $token = $this->requestPin();
        $application = Solicitud::firstOrFail();
        $year = AnoLectivo::firstOrFail();
        $target = Grado::firstOrFail();
        $group = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $target->id, 'nombre' => '01B', 'estado' => 'activo']);
        $prior = AnoLectivo::create(['nombre' => '2026', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
            'num_periodos' => 4, 'tipo_calendario' => 'A', 'estado' => 'cerrado']);
        $level = Nivel::create(['ano_lectivo_id' => $prior->id, 'nombre' => 'Preescolar', 'nivel_educativo' => 'preescolar', 'estado' => 'activo']);
        $transition = Grado::create(['ano_lectivo_id' => $prior->id, 'nivel_id' => $level->id, 'nombre' => 'Transición', 'estado' => 'activo']);
        $primary = Nivel::create(['ano_lectivo_id' => $prior->id, 'nombre' => 'Primaria', 'nivel_educativo' => 'primaria', 'estado' => 'activo']);
        $first = Grado::create(['ano_lectivo_id' => $prior->id, 'nivel_id' => $primary->id, 'nombre' => 'Primero', 'estado' => 'activo']);
        $priorGroup = Grupo::create(['ano_lectivo_id' => $prior->id, 'grado_id' => $transition->id, 'nombre' => 'A', 'estado' => 'activo']);
        $student = User::create(['name' => 'Ana Pruebas', 'email' => $application->email, 'role' => 'estudiante',
            'status' => 'active', 'must_change_password' => false, 'password' => 'Testing123!']);
        $student->assignRole('estudiante');
        $originalHash = $student->password;
        $anaToken = $student->createToken('cuenta-ana')->plainTextToken;
        $login = function (string $bearer) {
            app('auth')->forgetGuards();
            $this->withToken($bearer);
        };
        $datos = [...$application->datos, 'nacimiento' => '2019-02-14', 'telefono' => '+573001234567',
            'direccion' => 'Calle 1', 'pais' => 'CO', 'departamento' => '41', 'municipio' => '41001',
            'responsable' => 'María Pérez', 'parentesco' => 'Madre', 'telefono_responsable' => '+573001234567', 'adicionales' => []];
        DB::table('ingreso_perfiles')->insert(['estudiante_id' => $student->id, 'tipo_documento' => 'TI',
            'numero_documento' => '1234567', 'datos' => json_encode($datos), 'created_at' => now(), 'updated_at' => now()]);
        $oldEnrollment = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $priorGroup->id,
            'ano_lectivo_id' => $prior->id, 'estado' => 'activa']);
        $rector = User::where('role', 'rector')->firstOrFail();
        \App\Models\Academico\PromocionAcademica::create(['ano_lectivo_id' => $prior->id, 'matricula_id' => $oldEnrollment->id,
            'propuesta' => 'promovido', 'resultado' => 'promovido', 'grado_destino_id' => $first->id,
            'insumos' => [], 'huella' => str_repeat('a', 64), 'motivo' => 'Resultado académico',
            'reviewed_by' => $rector->id, 'approved_at' => now(), 'version' => 1]);
        Queue::fake();
        Storage::fake('tenant');
        $this->app->instance(FileScanner::class, new NullScanner);
        $this->mock(SchoolMail::class)->shouldReceive('sendEnrollment');
        $portal = $this->base.'/mi-renovacion/'.$token;
        $api = $this->base.'/ingreso/solicitudes/'.$token;
        $login($this->staffToken);
        $this->postJson($api.'/pin')->assertOk();
        $login($anaToken);
        $this->getJson($portal.'/solicitud')->assertOk()->assertJsonPath('data.estado', 'borrador');
        $this->postJson($portal.'/solicitud/renovacion', ['tipo_documento' => 'TI', 'numero_documento' => '1234567'])
            ->assertOk()->assertJsonPath('data.grado_token', $this->grade);
        $document = $this->post($portal.'/documentos/identidad', ['carga_token' => (string) Str::uuid(),
            'documento' => UploadedFile::fake()->image('identidad.png')], ['Accept' => 'application/json'])
            ->assertOk()->json('data.documentos.0.url_token');
        $this->postJson($portal.'/solicitud', ['datos' => $datos, 'grado_token' => $this->grade, 'enviar' => true,
            'consentimiento' => true, 'envio_token' => (string) Str::uuid(),
            'privacidad_version' => app(EnrollmentIntake::class)->campaign($application->campana)['privacidad_version']])->assertOk();
        $login($this->staffToken);
        $this->putJson($api.'/documentos/'.$document, ['estado' => 'aprobado'])->assertOk();
        $this->postJson($api.'/validar')->assertOk();
        $this->postJson($api.'/decision', ['estado' => 'aprobada', 'grado_token' => $this->grade])->assertOk();
        $classrooms = [];
        foreach ([$priorGroup, $group] as $classGroup) {
            $classYear = $classGroup->ano_lectivo_id;
            $area = \App\Models\Academico\Area::create(['ano_lectivo_id' => $classYear, 'nombre' => 'Ciencias', 'estado' => 'activo']);
            $subject = \App\Models\Academico\Materia::create(['ano_lectivo_id' => $classYear, 'area_id' => $area->id,
                'nombre' => 'Matemáticas', 'intensidad_horaria' => 4, 'estado' => 'activo']);
            DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $classYear, 'grado_id' => $classGroup->grado_id, 'materia_id' => $subject->id]);
            $aula = \App\Models\Academico\Aula::create(['ano_lectivo_id' => $classYear, 'grupo_id' => $classGroup->id, 'materia_id' => $subject->id]);
            $classrooms[] = $this->base.'/aula/'.OpaqueUrlToken::for('aula', $aula->id);
            if ($classYear === $prior->id) {
                $term = \App\Models\Academico\Periodo::create(['ano_lectivo_id' => $prior->id, 'orden' => 1,
                    'nombre' => 'Primer periodo', 'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-03-30', 'estado' => 'cerrado']);
                [$priorGrade] = $this->recordClassForTrajectory($oldEnrollment, $priorGroup, $term, $subject, $rector, CarbonImmutable::parse('2026-02-02'));
            }
        }
        [$oldRoom, $newRoom] = $classrooms;
        $yearToken = OpaqueUrlToken::for('ano-lectivo', $year->id);
        $access = $this->base.'/anos-lectivos/'.$yearToken.'/acceso-estudiantil?opaque=1';
        $catalog = $this->base.'/aula/catalogo?ano_token='.$yearToken;
        $me = $this->base.'/ingreso/mi-estado';
        $login($anaToken);
        $this->getJson($me)->assertOk()->assertJsonPath('tramites.0.token', $token)
            ->assertJsonPath('grupo_pendiente', true)->assertJsonPath('tramites.0.clases_habilitadas', false);
        $this->getJson($portal.'/solicitud')->assertOk()->assertJsonPath('data.estado', 'aprobada');
        $upload = ['documento' => UploadedFile::fake()->image('Soporte de Ana.png'), 'carga_token' => (string) Str::uuid()];
        $response = $this->post($portal.'/anexos', $upload, ['Accept' => 'application/json'])->assertOk();
        $annex = collect($response->json('data.documentos'))->firstWhere('anexo_renovacion', true);
        $this->assertSame('Soporte de Ana.png', $annex['nombre']);
        $this->post($portal.'/anexos', $upload, ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2, 'data.documentos');
        $this->get($portal.'/documentos/'.$annex['url_token'])->assertOk();
        $this->getJson($catalog)->assertOk()->assertJsonCount(0, 'data.aulas');
        $this->getJson($newRoom)->assertForbidden();
        $this->getJson($oldRoom)->assertOk();
        $this->putJson($access, ['habilitado' => true, 'motivo' => 'Desde el estudiante'])->assertForbidden();
        $other = User::create(['name' => 'Otra estudiante', 'email' => 'other@test.test', 'role' => 'estudiante',
            'password' => 'TestPassword123!', 'status' => 'active']);
        $other->assignRole('estudiante');
        $login($other->createToken('otra')->plainTextToken);
        $this->getJson($me)->assertOk()->assertJsonCount(0, 'tramites');
        $this->getJson($portal.'/solicitud')->assertNotFound();
        $this->getJson($portal.'/documentos/'.$annex['url_token'])->assertNotFound();
        $login($this->staffToken);
        $this->putJson($access, ['habilitado' => false, 'motivo' => 'Preparación del nuevo año'])->assertOk();
        $this->postJson($this->base.'/ingreso/campanas/'.$this->campaign.'/distribuir', [
            'confirmar' => true, 'asignaciones' => [['solicitud_token' => $token, 'grupo_token' => OpaqueUrlToken::for('grupo', $group->id)]],
        ])->assertOk();
        $login($anaToken);
        $this->getJson($me)->assertOk()->assertJsonPath('tramites.0.grupo', 'Primero / 01B')
            ->assertJsonPath('tramites.0.clases_habilitadas', false)->assertJsonPath('grupo_pendiente', false);
        $this->getJson($portal.'/solicitud')->assertOk()->assertJsonPath('data.estado', 'matriculada');
        $this->assertRenewalEmailsReceived($application, $group);
        $this->get($portal.'/documentos/'.$annex['url_token'])->assertOk();
        $this->postJson($portal.'/anexos', ['carga_token' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson($catalog)->assertOk()->assertJsonCount(0, 'data.aulas');
        $this->getJson($newRoom)->assertForbidden();
        $this->getJson($oldRoom)->assertOk();
        $login($this->staffToken);
        $this->putJson($access, ['habilitado' => true, 'motivo' => 'Inicio de clases en Primero'])->assertOk();
        $login($anaToken);
        $this->getJson($me)->assertOk()->assertJsonPath('tramites.0.clases_habilitadas', true);
        $this->getJson($catalog)->assertOk()->assertJsonCount(1, 'data.aulas')->assertJsonPath('data.aulas.0.materia', 'Matemáticas');
        $this->getJson($newRoom)->assertOk();
        $this->getJson($oldRoom)->assertOk();
        $this->assertSame($originalHash, $student->fresh()->password);
        $this->assertSame('promovido', \App\Models\Academico\PromocionAcademica::where('matricula_id', $oldEnrollment->id)->value('resultado'));
        $this->assertSame(2, Matricula::where('estudiante_id', $student->id)->count());
        $this->assertEquals(4, $priorGrade->fresh()->valor);
        $this->assertSame($oldEnrollment->id, $priorGrade->fresh()->matricula_id);
        $this->getJson($this->base.'/asistencias/mis-faltas?ano_lectivo_token='.OpaqueUrlToken::for('ano-lectivo', $prior->id))
            ->assertOk()->assertJsonPath('data.faltas.0.fecha', '2026-02-02')->assertJsonPath('data.faltas.0.materia', 'Matemáticas');
    }

    private function assertRenewalEmailsReceived(Solicitud $application, Grupo $group): void
    {
        $expected = [
            'Tu documentación fue validada' => 'La decisión de admisión se comunicará por separado.',
            'Tu inscripción fue admitida' => 'El colegio continuará con la matrícula y la asignación de grupo.',
            'Matrícula confirmada' => 'Tu matrícula fue confirmada en el grupo '.$group->nombre.'.',
        ];
        $notices = Notificacion::where('email', $application->email)->whereIn('asunto', array_keys($expected))
            ->orderBy('id')->get()->keyBy('id');
        $this->assertSame(array_keys($expected), $notices->pluck('asunto')->all());
        $jobs = Queue::pushed(SendEnrollmentNotice::class)->filter(fn ($job) => $notices->has($job->notice));
        $this->assertSame($notices->keys()->all(), $jobs->pluck('notice')->values()->all());

        $this->app->forgetInstance(SchoolMail::class);
        $this->assertNull(app(SchoolMail::class)->settings());
        $smtp = new Process([PHP_BINARY, base_path('tests/Support/admission-smtp-mailbox.php')]);
        $smtp->setTimeout(20);
        $smtp->start();
        try {
            $this->assertTrue($smtp->waitUntil(fn ($type, $out) => str_contains($out, 'ready 127.0.0.1:')));
            $this->assertSame(1, preg_match('/ready 127\.0\.0\.1:(\d+)/', $smtp->getOutput(), $address));
            config(['mail.default' => 'renewal-test', 'mail.from.address' => 'colegio@example.test',
                'mail.mailers.renewal-test' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => (int) $address[1], 'timeout' => 3]]);
            foreach ($jobs as $job) {
                $job->handle();
                $this->assertNotNull($notices[$job->notice]->refresh()->enviada_en);
            }
            $this->assertSame(3, preg_match_all('/^delivered (.+)$/m', $smtp->getOutput(), $received));
            foreach (array_keys($expected) as $index => $subject) {
                $mime = base64_decode(trim($received[1][$index]), true);
                [$headers, $body] = explode("\r\n\r\n", $mime, 2);
                $this->assertStringContainsString('To: '.$application->email."\r\n", $headers."\r\n");
                $this->assertSame(1, preg_match('/^Subject: (.+(?:\r\n[ \t].+)*)/m', $headers, $match));
                $this->assertSame($subject, mb_decode_mimeheader(trim($match[1])));
                $this->assertStringContainsString($expected[$subject], quoted_printable_decode($body));
            }
        } finally {
            Mail::purge('renewal-test');
            $smtp->stop(0);
        }
    }

    public function test_submitted_pin_stays_active_and_only_school_staff_can_reassign_the_grade(): void
    {
        $token = $this->requestPin();
        $application = Solicitud::firstOrFail();
        $first = Grado::firstOrFail();
        $second = Grado::create(['ano_lectivo_id' => $first->ano_lectivo_id, 'nivel_id' => $first->nivel_id, 'nombre' => 'Segundo', 'estado' => 'activo']);
        $secondToken = OpaqueUrlToken::for('grado', $second->id);
        $campaign = Campana::firstOrFail();
        $config = $campaign->configuracion;
        $config['grados'][] = ['token' => $secondToken, 'nombre' => 'Segundo', 'cupo' => 30];
        $config['verificar_identidad_automaticamente'] = false;
        $config['documento_identidad'] = 'identidad';
        $config['documentos'] = [
            ['key' => 'identidad', 'nombre' => 'Identidad', 'formatos' => ['png'], 'obligatorio' => true, 'grados' => []],
            ['key' => 'certificado', 'nombre' => 'Certificado de primero', 'formatos' => ['png'], 'obligatorio' => true, 'grados' => [$secondToken]],
        ];
        $campaign->update(['configuracion' => $config]);
        $application->update(['grado_id' => $first->id, 'requisitos_snapshot' => $config]);
        $group = Grupo::create(['ano_lectivo_id' => $first->ano_lectivo_id, 'grado_id' => $second->id, 'nombre' => 'Segundo A', 'codigo' => '02A', 'estado' => 'activo']);
        $this->mock(SchoolMail::class)->shouldReceive('sendEnrollment');
        $api = $this->base.'/ingreso/solicitudes/'.$token;
        app('auth')->forgetGuards();
        $activation = $this->withToken($this->staffToken)->postJson($api.'/pin')->assertOk();
        $originalPin = $activation->json('pin');
        $application->refresh();
        $hash = $application->pin_hash;
        $session = $application->sesion_version;
        Queue::fake();
        Storage::fake('tenant');
        $this->app->instance(FileScanner::class, new NullScanner);
        $created = null;
        app(EnrollmentSubmission::class)->stage(Request::create('/', 'POST', ['carga_token' => (string) Str::uuid()],
            files: ['documento' => UploadedFile::fake()->image('identidad.png')]), $application, 'identidad', $created);
        $application->documentos()->update(['estado' => 'aprobado']);
        $data = [...$application->datos, 'nacimiento' => '2018-02-14', 'telefono' => '+573001234567',
            'direccion' => 'Calle 1', 'pais' => 'CO', 'departamento' => '41', 'municipio' => '41001',
            'responsable' => 'María Pérez', 'parentesco' => 'Madre', 'telefono_responsable' => '+573001234567', 'adicionales' => []];
        $application->update(['estado' => 'enviada', 'enviada_en' => now(), 'consentimiento_en' => now(), 'email_verificado' => now(), 'datos' => $data]);
        $this->postJson($api.'/pin')->assertUnprocessable();
        $this->assertSame($hash, $application->fresh()->pin_hash);
        $this->assertSame($session, $application->fresh()->sesion_version);
        $this->getJson($api)->assertOk()->assertJsonPath('data.grado_nombre', 'Primero');
        $visitor = User::create(['name' => 'Docente', 'email' => 'docente-grado@example.test', 'password' => 'Testing123!', 'role' => 'docente', 'status' => 'active']);
        $visitor->assignRole('docente');
        app('auth')->forgetGuards();
        $this->withToken($visitor->createToken('test')->plainTextToken)->putJson($api.'/grado', ['grado_token' => $secondToken, 'observacion' => 'Nivelación académica.'])->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken)->putJson($api.'/grado', ['grado_token' => $secondToken, 'observacion' => ''])->assertUnprocessable();
        $changed = $this->putJson($api.'/grado', ['grado_token' => $secondToken, 'observacion' => 'Corresponde a segundo por sus estudios previos.'])
            ->assertOk()->assertJsonPath('data.estado', 'revision')->assertJsonPath('data.grado_token', $this->grade)
            ->assertJsonPath('data.grado_aprobado_token', $secondToken)->assertJsonPath('data.grado_aprobado_nombre', 'Segundo');
        $this->assertNull($changed->json('data.validada_en'));
        $this->getJson($api)->assertOk()->assertJsonPath('grupos.0.token', OpaqueUrlToken::for('grupo', $group->id));
        $this->postJson($api.'/validar')->assertUnprocessable();
        $this->postJson($api.'/correcciones', ['campo' => 'documentos.certificado', 'motivo' => 'Adjunta el certificado para segundo.', 'hasta' => now()->addWeek()->format('Y-m-d\TH:i')])
            ->assertOk()->assertJsonPath('data.correcciones.0.version', 0);
        $this->withoutHeader('Authorization');
        app('auth')->forgetGuards();
        $access = $this->postJson($this->base.'/ingreso-publico/consultar', ['tipo_documento' => 'TI', 'numero_documento' => '1234567', 'pin' => $originalPin])
            ->assertOk()->assertJsonPath('data.grado_nombre', 'Primero')->assertJsonPath('data.grado_aprobado_nombre', 'Segundo');
        foreach ($access->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
            if ($cookie->getName() === 'ingreso_csrf') {
                $this->withHeader('X-CSRF-Token', $cookie->getValue());
            }
        }
        $this->putJson($this->base.'/ingreso-publico/solicitud/borrador', ['datos' => $data, 'grado_token' => $secondToken])
            ->assertUnprocessable()->assertJsonValidationErrors('grado_token');
        $this->post($this->base.'/ingreso-publico/documentos/certificado', ['carga_token' => (string) Str::uuid(), 'documento' => UploadedFile::fake()->image('certificado.png')], ['Accept' => 'application/json'])->assertOk();
        $this->postJson($this->base.'/ingreso-publico/solicitud', ['datos' => $data, 'grado_token' => $this->grade,
            'enviar' => true, 'consentimiento' => true, 'envio_token' => (string) Str::uuid(),
            'privacidad_version' => app(EnrollmentIntake::class)->campaign($campaign)['privacidad_version']])->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken);
        $application->documentos()->where('requisito', 'certificado')->update(['estado' => 'aprobado']);
        $this->postJson($api.'/validar')->assertOk();
        $this->postJson($api.'/decision', ['estado' => 'aprobada', 'grado_token' => $secondToken])->assertOk()
            ->assertJsonPath('data.grado_aprobado_nombre', 'Segundo');
        $this->assertSame($first->id, $application->fresh()->grado_id);
        $this->assertSame($second->id, $application->fresh()->grado_aprobado_id);
    }

    public function test_document_storage_lifecycle_deduplicates_retries_and_manual_review_skips_ocr(): void
    {
        $this->requestPin();
        Storage::fake('tenant');
        Queue::fake();
        $this->app->instance(FileScanner::class, new NullScanner);
        $this->mock(IdentityDocumentReader::class, function ($mock): void {
            $mock->shouldNotReceive('read');
            $mock->shouldNotReceive('validate');
        });
        $campaign = Campana::firstOrFail();
        $campaign->update(['configuracion' => [...$campaign->configuracion, 'verificar_identidad_automaticamente' => false,
            'documento_identidad' => 'identidad', 'documentos' => [['key' => 'identidad', 'nombre' => 'Identidad',
                'obligatorio' => true, 'formatos' => ['png'], 'grados' => []]]]]);
        $application = Solicitud::firstOrFail();
        $application->update(['grado_id' => Grado::firstOrFail()->id, 'estado' => 'borrador', 'requisitos_snapshot' => $campaign->configuracion]);
        $file = UploadedFile::fake()->image('identidad.png');
        $service = app(EnrollmentSubmission::class);
        foreach ([1, 2] as $attempt) {
            $request = Request::create('/', 'POST', ['carga_token' => (string) Str::uuid()], files: ['documento' => $file]);
            $created = null;
            $service->stage($request, $application, 'identidad', $created);
            $this->assertSame($attempt === 1, $created !== null);
        }
        $this->assertSame(1, StoredFile::count());
        $stored = StoredFile::firstOrFail();
        $draft = $stored->path;
        $this->assertStringContainsString('/matriculas/Borradores/', $draft);
        $data = [...$application->datos, 'nacimiento' => '2018-02-14', 'telefono' => '+573001234567',
            'direccion' => 'Calle 1', 'pais' => 'CO', 'departamento' => '41', 'municipio' => '41001',
            'responsable' => 'María Pérez', 'parentesco' => 'Madre', 'telefono_responsable' => '+573001234567', 'adicionales' => []];
        $request = Request::create('/', 'POST', ['datos' => $data, 'enviar' => true, 'consentimiento' => true,
            'envio_token' => (string) Str::uuid(), 'privacidad_version' => app(EnrollmentIntake::class)->campaign($campaign)['privacidad_version']]);
        $created = [];
        DB::transaction(fn () => $service->submit($request, $application, $created));
        $submitted = $stored->fresh()->path;
        $this->assertStringContainsString('/matriculas/Solicitudes/', $submitted);
        $this->assertStringContainsString('Ana_Pruebas', $submitted);
        Storage::disk('tenant')->assertMissing($draft);
        Storage::disk('tenant')->assertExists($submitted);
        $this->assertSame($stored->checksum, hash_file('sha256', Storage::disk('tenant')->path($submitted)));
        $organizer = app(EnrollmentStorage::class);
        $organizer->organize($application->id);
        $this->assertSame($submitted, $stored->fresh()->path);
        $this->assertSame(dirname($submitted), StorageService::tenantFolder(tenant()).'/'.$organizer->uploadFolder($application->fresh(), 'identidad', $file));
        $student = User::create(['name' => 'Ana Pruebas', 'email' => 'matriculada@example.test', 'password' => 'Testing123!', 'role' => 'estudiante', 'status' => 'active']);
        $group = Grupo::create(['ano_lectivo_id' => $campaign->ano_lectivo_id,
            'grado_id' => $application->grado_id, 'nombre' => 'Primero A', 'codigo' => '01A', 'estado' => 'activo']);
        $enrollment = Matricula::create(['ano_lectivo_id' => $campaign->ano_lectivo_id,
            'grupo_id' => $group->id, 'estudiante_id' => $student->id, 'estado' => 'activa']);
        $application->update(['estado' => 'matriculada', 'matricula_id' => $enrollment->id]);
        $organizer->organize($application->id);
        $this->assertStringContainsString('/anos-lectivos/2027/matriculas/Matriculados/', $stored->fresh()->path);
        Storage::disk('tenant')->assertMissing($submitted);
        $this->assertCount(1, Storage::disk('tenant')->allFiles());
    }

    public function test_private_campaign_and_optional_grade_survive_pin_access_and_draft_reload(): void
    {
        $this->getJson($this->base.'/ingreso-publico/catalogo')->assertOk()->assertJsonPath('campanas', [])
            ->assertDontSee('Convocatoria compartida');
        $this->getJson($this->base.'/ingreso-publico/campanas/'.$this->campaign)->assertOk()
            ->assertJsonPath('data.url_token', $this->campaign);
        $application = $this->requestPin();
        $this->assertNull(Solicitud::firstOrFail()->grado_id);
        $this->assertSame(0, Notificacion::count());
        $this->mock(SchoolMail::class)->shouldReceive('sendEnrollment')->once();
        app('auth')->forgetGuards();
        $activation = $this->withToken($this->staffToken)
            ->postJson($this->base.'/ingreso/solicitudes/'.$application.'/pin', ['expira_en' => null])->assertOk();
        $this->assertNotNull(Notificacion::firstOrFail()->enviada_en);
        $this->withoutHeader('Authorization');
        app('auth')->forgetGuards();
        $access = $this->postJson($this->base.'/ingreso-publico/consultar', ['tipo_documento' => 'TI',
            'numero_documento' => '1234567', 'pin' => $activation->json('pin')])->assertOk()->assertJsonPath('data.grado_token', null);
        foreach ($access->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
            if ($cookie->getName() === 'ingreso_csrf') {
                $this->withHeader('X-CSRF-Token', $cookie->getValue());
            }
        }
        $this->postJson($this->base.'/ingreso-publico/solicitud', ['enviar' => true, 'envio_token' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('grado_token');
        $this->putJson($this->base.'/ingreso-publico/solicitud/borrador', ['datos' => $access->json('data.datos'), 'grado_token' => $this->grade])
            ->assertOk()->assertJsonPath('data.grado_token', $this->grade);
        $this->getJson($this->base.'/ingreso-publico/solicitud')->assertOk()->assertJsonPath('data.grado_token', $this->grade);
        $this->putJson($this->base.'/ingreso-publico/solicitud/borrador', ['datos' => $access->json('data.datos'), 'grado_token' => str_repeat('x', 24)])
            ->assertNotFound();
    }

    public function test_failed_pin_email_keeps_the_notice_pending_and_returns_an_explicit_error(): void
    {
        $application = $this->requestPin();
        $this->mock(SchoolMail::class)->shouldReceive('sendEnrollment')->once()->andThrow(new EnrollmentMailDeliveryException('Transport unavailable'));
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken)->postJson($this->base.'/ingreso/solicitudes/'.$application.'/pin', ['expira_en' => null])
            ->assertStatus(503)->assertSee('no se pudo enviar el correo');
        $this->assertNull(Notificacion::firstOrFail()->enviada_en);
        $this->assertSame('borrador', Solicitud::firstOrFail()->estado);
    }

    public function test_birth_dates_use_the_school_day_and_optional_grade_range_from_draft_to_submission(): void
    {
        // UTC is already tomorrow at 23:00 in Bogotá.
        $evening = now('America/Bogota')->setTime(23, 0);
        $this->travelTo($evening);
        $today = $evening->toDateString();
        $tomorrow = $evening->copy()->addDay()->toDateString();
        $token = $this->requestPin();
        $campaign = Campana::firstOrFail();
        $campaign->update(['configuracion' => [...$campaign->configuracion, 'verificar_identidad_automaticamente' => false,
            'documento_identidad' => 'identidad', 'documentos' => [['key' => 'identidad', 'nombre' => 'Identidad',
                'obligatorio' => true, 'formatos' => ['png'], 'grados' => []]]]]);
        $application = Solicitud::firstOrFail();
        $application->update(['requisitos_snapshot' => $campaign->configuracion]);
        $this->mock(SchoolMail::class)->shouldReceive('sendEnrollment')->once();
        app('auth')->forgetGuards();
        $pin = $this->withToken($this->staffToken)->postJson($this->base.'/ingreso/solicitudes/'.$token.'/pin')->assertOk()->json('pin');
        $this->withoutHeader('Authorization');
        app('auth')->forgetGuards();
        $root = $this->base.'/ingreso-publico';
        $access = $this->postJson($root.'/consultar', ['tipo_documento' => 'TI', 'numero_documento' => '1234567', 'pin' => $pin])->assertOk();
        foreach ($access->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
            if ($cookie->getName() === 'ingreso_csrf') {
                $this->withHeader('X-CSRF-Token', $cookie->getValue());
            }
        }
        $rule = Grado::firstOrFail()->ingresoGrado()->firstOrFail();
        $rule->update(['edad_min' => null, 'edad_max' => null]);
        $data = [...$application->datos, 'nacimiento' => $evening->copy()->startOfYear()->toDateString(), 'telefono' => '+573001234567',
            'direccion' => 'Calle 1', 'pais' => 'CO', 'departamento' => '41', 'municipio' => '41001',
            'responsable' => 'María Pérez', 'parentesco' => 'Madre', 'telefono_responsable' => '+573001234567', 'adicionales' => []];
        $this->putJson($root.'/solicitud/borrador', ['datos' => $data, 'grado_token' => $this->grade])
            ->assertOk()->assertJsonPath('data.datos.nacimiento', $data['nacimiento']);
        $this->putJson($root.'/solicitud/borrador', ['datos' => [...$data, 'nacimiento' => $tomorrow]])
            ->assertUnprocessable()->assertJsonValidationErrors('datos.nacimiento');
        $this->assertSame($data['nacimiento'], $application->fresh()->datos['nacimiento']);
        $payload = ['enviar' => true, 'consentimiento' => true, 'envio_token' => (string) Str::uuid(),
            'privacidad_version' => $access->json('data.campana.privacidad_version')];
        $this->postJson($root.'/solicitud', [...$payload, 'datos' => [...$data, 'nacimiento' => $tomorrow]])
            ->assertUnprocessable()->assertJsonValidationErrors('datos.nacimiento');
        $rule->update(['edad_min' => 6, 'edad_max' => 8]);
        $this->postJson($root.'/solicitud', [...$payload, 'datos' => $data])
            ->assertUnprocessable()->assertJsonValidationErrors('datos.nacimiento');
        $this->assertNull($application->fresh()->enviada_en);
        $rule->update(['edad_min' => null, 'edad_max' => null]);
        Queue::fake();
        Storage::fake('tenant');
        $this->app->instance(FileScanner::class, new NullScanner);
        $this->post($root.'/documentos/identidad', ['carga_token' => (string) Str::uuid(), 'documento' => UploadedFile::fake()->image('identidad.png')],
            ['Accept' => 'application/json'])->assertOk();
        $this->postJson($root.'/solicitud', [...$payload, 'datos' => [...$data, 'nacimiento' => $today]])
            ->assertOk()->assertJsonPath('data.estado', 'enviada')->assertJsonPath('data.validacion_edad.edad', 0);
        $snapshot = $application->fresh()->validacion_edad;
        $this->assertSame($today, $snapshot['fecha']);
        $this->assertNull($snapshot['edad_min']);
    }

    public function test_log_mailer_cannot_report_a_pin_as_delivered(): void
    {
        $application = $this->requestPin();
        config(['mail.default' => 'log']);
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken)->postJson($this->base.'/ingreso/solicitudes/'.$application.'/pin', ['expira_en' => null])
            ->assertStatus(503);
        $this->assertNull(Notificacion::firstOrFail()->enviada_en);
    }

    public function test_school_age_configuration_rejects_unrealistic_limits_and_checks_birthdays(): void
    {
        app('auth')->forgetGuards();
        $current = $this->withToken($this->staffToken)->getJson($this->base.'/ingreso/configuracion')->assertOk()->json('data');
        $input = ['version' => $current['version'], 'privacidad' => 'Aviso de privacidad del colegio para inscripciones.',
            'grados' => array_map(fn ($g) => ['url_token' => $g['url_token'], 'edad_min' => 100, 'edad_max' => 120], $current['grados'])];
        $this->putJson($this->base.'/ingreso/configuracion', $input)->assertUnprocessable()
            ->assertJsonValidationErrors(['grados.0.edad_min', 'grados.0.edad_max']);
        $input['grados'][0]['edad_min'] = 6;
        $input['grados'][0]['edad_max'] = 8;
        $this->putJson($this->base.'/ingreso/configuracion', $input)->assertOk()->assertJsonPath('data.grados.0.edad_max', 8);
        $this->withoutHeader('Authorization');
        app('auth')->forgetGuards();
        $this->requestPin();
        $application = Solicitud::firstOrFail();
        $application->grado_id = Grado::firstOrFail()->id;
        $settings = app(EnrollmentSettings::class);
        $date = CarbonImmutable::now('America/Bogota')->startOfDay();
        foreach ([6, 8] as $age) {
            $this->assertSame($age, $settings->ageValidation($application, ['nacimiento' => $date->subYears($age)->toDateString()])['edad']);
        }
        foreach ([5, 9, 100] as $age) {
            try {
                $settings->ageValidation($application, ['nacimiento' => $date->subYears($age)->toDateString()]);
                $this->fail('Birth outside the grade range was accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('datos.nacimiento', $error->errors());
            }
        }
    }

    public function test_policy_changes_require_fresh_consent_and_preserve_the_complete_accepted_version(): void
    {
        $this->requestPin();
        $campaign = Campana::firstOrFail();
        $campaign->update(['configuracion' => [...$campaign->configuracion, 'documento_identidad' => 'identidad',
            'documentos' => [['key' => 'identidad', 'nombre' => 'Identidad', 'obligatorio' => true, 'formatos' => ['png'], 'grados' => []]]]]);
        $application = Solicitud::firstOrFail();
        $application->update(['grado_id' => Grado::firstOrFail()->id, 'estado' => 'borrador', 'requisitos_snapshot' => $campaign->configuracion]);
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken);
        $settings = app(EnrollmentSettings::class)->present();
        $input = ['version' => $settings['version'], 'privacidad' => 'Aviso del colegio sobre datos para inscripción.',
            'politica_datos' => 'Política de tratamiento con derechos, finalidades y contacto del colegio.',
            'grados' => array_map(fn ($g) => array_intersect_key($g, array_flip(['url_token', 'edad_min', 'edad_max'])), $settings['grados'])];
        $this->putJson($this->base.'/ingreso/configuracion', $input)->assertOk()->assertJsonPath('data.politica_datos', $input['politica_datos']);
        $version = app(EnrollmentIntake::class)->campaign($campaign)['privacidad_version'];
        $input['version']++;
        $input['politica_datos'] .= ' Nueva versión de la política.';
        $this->putJson($this->base.'/ingreso/configuracion', $input)->assertOk();
        Storage::fake('local');
        $file = StoredFile::create(['tenant_id' => $this->school->id, 'disk' => 'local', 'path' => 'identity-test.png',
            'mime' => 'image/png', 'size' => 12, 'checksum' => hash('sha256', 'fixture'), 'original_name' => 'identity.png']);
        $application->documentos()->create(['requisito' => 'identidad', 'version' => 1, 'archivo_id' => $file->id,
            'nombre' => 'identity.png', 'estado' => 'pendiente']);
        $data = ['primer_nombre' => 'Ana', 'primer_apellido' => 'Pruebas', 'nacimiento' => '2018-02-14',
            'tipo_documento' => 'TI', 'numero_documento' => '1234567', 'telefono' => '+573186037751',
            'direccion' => 'Calle de prueba', 'pais' => 'CO', 'departamento' => '41', 'municipio' => '41001',
            'responsable' => 'Representante de prueba', 'parentesco' => 'Madre', 'telefono_responsable' => '+573186037751', 'adicionales' => []];
        $request = Request::create('/', 'POST', ['datos' => $data, 'consentimiento' => true, 'enviar' => true,
            'envio_token' => (string) Str::uuid(), 'privacidad_version' => $version]);
        try {
            $created = [];
            DB::transaction(fn () => app(EnrollmentSubmission::class)->submit($request, $application, $created));
            $this->fail('An outdated policy was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('privacidad_version', $error->errors());
        }
        $this->assertNull($application->fresh()->enviada_en);
        $request->merge(['privacidad_version' => app(EnrollmentIntake::class)->campaign($campaign)['privacidad_version']]);
        foreach (['telefono', 'telefono_responsable'] as $field) {
            foreach (['+57318603775', '+5731860377519', '+1201555012', '+120155501239', '+52551234567', '+5255123456789'] as $invalid) {
                $request->merge(['datos' => [...$data, $field => $invalid]]);
                try {
                    DB::transaction(fn () => app(EnrollmentSubmission::class)->submit($request, $application, $created));
                    $this->fail('An invalid mobile number was accepted.');
                } catch (ValidationException $error) {
                    $this->assertArrayHasKey('datos.'.$field, $error->errors());
                }
            }
        }
        $request->merge(['datos' => $data]);
        $this->mock(IdentityDocumentReader::class, function ($mock) {
            $mock->shouldReceive('read')->once()->andReturn('Identity fixture');
            $mock->shouldReceive('validate')->once();
        });
        DB::transaction(fn () => app(EnrollmentSubmission::class)->submit($request, $application, $created));
        $evidence = $application->fresh()->consentimiento_privacidad;
        $this->assertSame($input['politica_datos'], $evidence['politica_datos']);
        $this->assertNotEmpty($evidence['aceptado_en']);
        $history = json_decode(DB::table('ingreso_envios')->first()->consentimiento_privacidad, true);
        $this->assertSame($evidence, $history);
        $input['version']++;
        $input['politica_datos'] .= ' Otro cambio posterior.';
        $this->putJson($this->base.'/ingreso/configuracion', $input)->assertOk();
        $this->assertSame($evidence, $application->fresh()->consentimiento_privacidad);
    }

    public function test_public_country_metadata_and_drafts_enforce_mobile_lengths_without_requiring_unique_numbers(): void
    {
        $countries = collect($this->getJson($this->base.'/ingreso-publico/indicativos')->assertOk()->json('countries'))->keyBy('country');
        foreach (['CO' => '+573186037751', 'US' => '+12015550123', 'MX' => '+525512345678'] as $country => $phone) {
            $this->assertSame([10], $countries[$country]['lengths']);
            $this->assertSame($phone, ContactNumbers::normalize($phone, ContactNumbers::countryForNumber($phone), 'test'));
        }
        $this->requestPin();
        $application = Solicitud::firstOrFail();
        $application->update(['estado' => 'borrador']);
        $submission = app(EnrollmentSubmission::class);
        $original = $application->datos;
        foreach (['+573186037751', '+12015550123', '+525512345678', '+12'] as $phone) {
            $data = [...$original, 'telefono' => $phone, 'telefono_responsable' => $phone, 'adicionales' => []];
            $submission->draft(Request::create('/', 'PUT', ['datos' => $data]), $application);
            $this->assertSame($phone, $application->fresh()->datos['telefono']);
            $this->assertSame($phone, $application->fresh()->datos['telefono_responsable']);
        }
        foreach (['telefono', 'telefono_responsable'] as $field) {
            foreach (['+5731860377519', '+120155501239', '+5255123456789'] as $invalid) {
                try {
                    $submission->draft(Request::create('/', 'PUT', ['datos' => [...$original, 'adicionales' => [], $field => $invalid]]), $application);
                    $this->fail('An oversized draft mobile was accepted.');
                } catch (ValidationException $error) {
                    $this->assertArrayHasKey('datos.'.$field, $error->errors());
                }
            }
        }
    }

    public function test_pin_requests_reject_invalid_names_ids_emails_and_malformed_shapes_before_creating_records(): void
    {
        $good = ['primer_nombre' => 'Ana-María', 'primer_apellido' => "O'Connor", 'email' => 'ana@example.test',
            'tipo_documento' => 'TI', 'numero_documento' => '1234567'];
        // Keep throttling enabled for these malformed requests: hashing an array must not produce a server error.
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaign.'/iniciar', [...$good, 'email' => ['unexpected']])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => ['unexpected'], 'numero_documento' => ['unexpected'], 'tipo_documento' => 'TI'])
            ->assertUnprocessable();
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (['primer_nombre' => 'Ana7', 'primer_apellido' => '<script>alert(1)</script>', 'numero_documento' => 'A123', 'email' => 'sin-arroba'] as $field => $value) {
            $this->postJson($this->base.'/ingreso-publico/'.$this->campaign.'/iniciar', [...$good, $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame(0, Solicitud::count());
        }
        foreach (['CC', 'TI', 'RC'] as $type) {
            $this->postJson($this->base.'/ingreso-publico/'.$this->campaign.'/iniciar', [...$good, 'tipo_documento' => $type, 'numero_documento' => 'AB123'])
                ->assertUnprocessable()->assertJsonValidationErrors('numero_documento');
        }
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaign.'/iniciar', $good)->assertAccepted();
        $this->assertSame('Ana-María', Solicitud::firstOrFail()->datos['primer_nombre']);
        $this->assertSame("O'Connor", Solicitud::firstOrFail()->datos['primer_apellido']);
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaign.'/iniciar', [...$good, 'email' => 'foreign@example.test',
            'tipo_documento' => 'PASAPORTE', 'numero_documento' => 'AB-12345'])->assertAccepted();
    }

    public function test_drafts_and_final_submissions_validate_fixed_and_inferred_fields_without_executing_input_as_sql(): void
    {
        $this->requestPin();
        $application = Solicitud::firstOrFail();
        $campaign = Campana::firstOrFail();
        $fields = [
            ['key' => 'representante_nombre', 'nombre' => 'Nombre del representante', 'tipo' => 'texto', 'obligatorio' => true],
            ['key' => 'cantidad', 'nombre' => 'Cantidad', 'tipo' => 'numero', 'obligatorio' => true],
            ['key' => 'contacto_email', 'nombre' => 'Correo de contacto', 'tipo' => 'texto', 'obligatorio' => true],
            ['key' => 'fecha', 'nombre' => 'Fecha', 'tipo' => 'fecha', 'obligatorio' => true],
            ['key' => 'jornada', 'nombre' => 'Jornada', 'tipo' => 'seleccion', 'opciones' => ['Mañana', 'Tarde'], 'obligatorio' => true],
            ['key' => 'procedencia', 'nombre' => 'Nombre del colegio de procedencia', 'tipo' => 'texto', 'obligatorio' => true],
            ['key' => 'parentesco_extra', 'nombre' => 'Parentesco del representante', 'tipo' => 'texto', 'obligatorio' => true],
        ];
        $campaign->update(['configuracion' => [...$campaign->configuracion, 'campos' => $fields]]);
        $application->update(['grado_id' => Grado::firstOrFail()->id, 'estado' => 'borrador', 'requisitos_snapshot' => $campaign->configuracion]);
        $good = [...$application->datos, 'madre' => 'María Pérez', 'padre' => 'Óscar Muñoz', 'responsable' => "D'Angelo Pérez",
            'parentesco' => 'Madre', 'telefono' => '+573186037751', 'telefono_responsable' => '+573186037751',
            'nacimiento' => '2018-02-14', 'direccion' => "Calle 1'); DROP TABLE users; --", 'pais' => 'CO', 'departamento' => '41', 'municipio' => '41001',
            'adicionales' => ['representante_nombre' => 'María-José Pérez', 'cantidad' => '0012', 'contacto_email' => 'contacto@example.test',
                'fecha' => '2020-03-14', 'jornada' => 'Mañana', 'procedencia' => 'Colegio 7 de Agosto', 'parentesco_extra' => 'Tía materna']];
        $service = app(EnrollmentSubmission::class);
        $users = User::count();
        $service->draft(Request::create('/', 'PUT', ['datos' => $good]), $application);
        $this->assertSame($good['direccion'], $application->fresh()->datos['direccion']);
        $this->assertSame($users, User::count());
        $this->assertSame('0012', $application->fresh()->datos['adicionales']['cantidad']);
        $bad = [];
        foreach (EnrollmentInput::NAME_FIELDS as $field) {
            $bad[] = [$field, 'Nombre1'];
        }
        $bad = [...$bad, ['numero_documento', 'ABC123'], ['direccion', '<svg onload=alert(1)>'], ['parentesco', 'Abuela123'],
            ['adicionales.parentesco_extra', 'Tía7'],
            ['adicionales.representante_nombre', 'Nombre1'], ['adicionales.cantidad', '12A'], ['adicionales.fecha', '2026-02-30'],
            ['adicionales.contacto_email', 'a@@b'], ['adicionales.jornada', 'Desconocida']];
        foreach ($bad as [$field, $value]) {
            $data = $good;
            data_set($data, $field, $value);
            try {
                $service->draft(Request::create('/', 'PUT', ['datos' => $data]), $application);
                $this->fail('Invalid draft data was stored: '.$field);
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('datos.'.$field, $error->errors());
            }
            $this->assertSame($good, $application->fresh()->datos);
        }
        foreach ([['primer_nombre', 'Ana1'], ['adicionales.representante_nombre', 'Madre2'], ['adicionales.contacto_email', 'sin-arroba'],
            ['parentesco', 'Padre1'], ['parentesco', 'oTrO'], ['adicionales.parentesco_extra', 'Tutor123'], ['adicionales.parentesco_extra', 'Otro'],
            ['adicionales.cantidad', '12A'], ['adicionales.fecha', '2026-02-30'], ['adicionales.jornada', 'Otra']] as [$field, $value]) {
            $data = $good;
            data_set($data, $field, $value);
            $request = Request::create('/', 'POST', ['datos' => $data, 'enviar' => true, 'consentimiento' => true,
                'envio_token' => (string) Str::uuid(), 'privacidad_version' => app(EnrollmentIntake::class)->campaign($campaign)['privacidad_version']]);
            try {
                $created = [];
                DB::transaction(fn () => $service->submit($request, $application, $created));
                $this->fail('Invalid final data was accepted: '.$field);
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('datos.'.$field, $error->errors());
            }
            $this->assertNull($application->fresh()->enviada_en);
        }
    }

    public function test_tenant_and_platform_login_reject_injection_inputs_and_preserve_legitimate_password_characters(): void
    {
        tenancy()->initialize($this->school);
        $password = 'L3git\'";<tag>--Pass';
        foreach (['tenant', 'platform'] as $scope) {
            if ($scope === 'platform') {
                tenancy()->end();
                DB::setDefaultConnection(config('tenancy.database.central_connection'));
                $this->withHeader('Origin', 'http://localhost');
                User::create(['name' => 'Platform validation', 'email' => 'platform-validation@example.test', 'password' => Hash::make($password),
                    'role' => 'superadmin', 'status' => 'active', 'must_change_password' => false]);
            }
            $user = User::where('role', $scope === 'tenant' ? 'rector' : 'superadmin')->firstOrFail();
            $user->forceFill(['password' => Hash::make($password), 'must_change_password' => false])->save();
            $base = $scope === 'tenant' ? $this->base : 'http://localhost/api';
            $before = DB::table('personal_access_tokens')->count();
            foreach ([['email' => "\"x' OR 1=1 --\"@example.test", 'password' => 'x'], ['email' => $user->email, 'password' => "' OR 1=1 --"],
                ['email' => ['unexpected'], 'password' => 'x'], ['email' => $user->email, 'password' => str_repeat('x', 4097)]] as $input) {
                app('auth')->forgetGuards();
                $response = $this->postJson($base.'/login', $input)->assertUnprocessable()->assertJsonMissingPath('token');
                if (is_string($input['email']) && str_contains($input['email'], ' OR ')) {
                    $response->assertJsonPath('errors.email.0', 'Credenciales invalidas.');
                }
                $this->assertSame($before, DB::table('personal_access_tokens')->count());
            }
            app('auth')->forgetGuards();
            $this->postJson($base.'/login', ['email' => $user->email, 'password' => $password])->assertOk()->assertJsonMissingPath('token');
            $this->assertSame($before + 1, DB::table('personal_access_tokens')->count());
        }
    }

    public function test_pin_validity_uses_the_campaign_policy_and_ignores_per_application_dates(): void
    {
        $application = $this->requestPin();
        $this->mock(SchoolMail::class)->shouldReceive('sendEnrollment')->twice();
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken)->postJson($this->base.'/ingreso/solicitudes/'.$application.'/pin',
            ['expira_en' => now()->addDay()->format('Y-m-d\TH:i')])->assertOk()->assertJsonPath('data.pin_expira_en', null);
        $this->assertNull(Solicitud::firstOrFail()->pin_expira);
        $deadline = CarbonImmutable::now('America/Bogota')->addDays(2)->startOfMinute();
        $campaign = Campana::firstOrFail();
        $campaign->update(['configuracion' => [...$campaign->configuracion, 'pin_expira_en' => $deadline->format('Y-m-d\TH:i')]]);
        $this->postJson($this->base.'/ingreso/solicitudes/'.$application.'/pin', [])->assertOk();
        $this->assertSame($deadline->timestamp, Solicitud::firstOrFail()->pin_expira->timestamp);
        $hash = Solicitud::firstOrFail()->pin_hash;
        $campaign->update(['configuracion' => [...$campaign->configuracion, 'pin_expira_en' => now()->subDay()->format('Y-m-d\TH:i')]]);
        $this->postJson($this->base.'/ingreso/solicitudes/'.$application.'/pin', [])->assertUnprocessable();
        $this->assertSame($hash, Solicitud::firstOrFail()->pin_hash);
    }
}
