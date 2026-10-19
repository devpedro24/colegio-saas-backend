<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaReady;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Jobs\SendEnrollmentNotice;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Nivel;
use App\Models\Academico\PromocionAcademica;
use App\Models\CorreoConfiguracion;
use App\Models\Ingreso\Campana;
use App\Models\Ingreso\Documento;
use App\Models\Ingreso\Notificacion;
use App\Models\Ingreso\Solicitud;
use App\Models\Plan;
use App\Models\SchoolMailChangeRequest;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ingreso\EnrollmentIntake;
use App\Services\Ingreso\EnrollmentMail;
use App\Services\Ingreso\IdentityDocumentReader;
use App\Services\SchoolMail;
use App\Services\TenantOnboarding;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\FileScanner;
use App\Support\Storage\StorageException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Mailer;
use Illuminate\Queue\Connectors\SyncConnector;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EnrollmentIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $school;

    private User $rector;

    private AnoLectivo $year;

    private Grado $grade;

    private Grupo $group;

    private string $base = 'http://intake.localhost/api';

    private string $campaignToken;

    private string $applicationToken;

    private array $cookies = [];

    private array $pendingFiles = [];

    private string $submissionKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        Queue::fake();
        $this->partialMock(IdentityDocumentReader::class, function ($mock) {
            $mock->shouldReceive('read')->andReturn("NUMERO 123.456.789\nAPELLIDOS PRUEBAS\nNOMBRES ANA\nFECHA DE NACIMIENTO 14-FEB-2018");
        });
        Storage::fake('tenant');
        config(['storage.scanner' => 'null', 'storage.disk' => 'tenant', 'tenancy.bootstrappers' => [],
            'database.connections.intake_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        $this->school = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'intake-test', 'name' => 'Colegio pruebas',
            'slug' => 'intake', 'plan' => 'esencial', 'status' => 'active']));
        $this->school->domains()->create(['domain' => 'intake']);
        Plan::create(['key' => 'esencial', 'name' => 'Esencial', 'features' => ['academico'], 'max_estudiantes' => 300]);
        DB::setDefaultConnection('intake_test');
        Artisan::call('migrate', ['--database' => 'intake_test', '--path' => 'database/migrations/tenant', '--force' => true]);
        tenancy()->initialize($this->school);
        $role = Role::findOrCreate('rector', 'web');
        Role::findOrCreate('estudiante', 'web');
        foreach (['configurar', 'ver', 'revisar', 'decidir', 'cambiar_grado', 'asignar'] as $p) {
            $role->givePermissionTo(Permission::findOrCreate('ingreso.'.$p, 'web'));
        }
        $this->rector = User::create(['name' => 'Rector', 'email' => 'rector@test.test', 'password' => 'TestPassword123!', 'role' => 'rector', 'status' => 'active']);
        $this->rector->assignRole('rector');
        $this->year = AnoLectivo::create(['nombre' => '2026', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'num_periodos' => 4, 'tipo_calendario' => 'A', 'estado' => 'en_curso']);
        $level = Nivel::create(['ano_lectivo_id' => $this->year->id, 'nombre' => 'Primaria', 'nivel_educativo' => 'primaria', 'estado' => 'activo']);
        $this->grade = Grado::create(['ano_lectivo_id' => $this->year->id, 'nivel_id' => $level->id, 'nombre' => 'Primero', 'estado' => 'activo']);
        $this->group = Grupo::create(['ano_lectivo_id' => $this->year->id, 'grado_id' => $this->grade->id, 'nombre' => '01A', 'estado' => 'activo', 'cupo_maximo' => 1]);
        $this->withoutMiddleware([EnsureOnboardingComplete::class, EnsureMfaReady::class]);
        $this->staff();
        $this->campaignToken = $this->postJson($this->base.'/ingreso/campanas', $this->configuration())->assertOk()->json('data.url_token');
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        DB::setDefaultConnection('sqlite');
        DB::purge('intake_test');
        parent::tearDown();
    }

    private function configuration(): array
    {
        return ['nombre' => 'Ingreso 2026', 'ano_token' => OpaqueUrlToken::for('ano-lectivo', $this->year->id), 'abierta' => true,
            'desde' => today()->subDay()->toDateString(), 'hasta' => today()->addMonth()->toDateString(),
            'configuracion' => ['pin_expira_en' => now('America/Bogota')->addDays(7)->format('Y-m-d\TH:i'),
                'grados' => [['token' => OpaqueUrlToken::for('grado', $this->grade->id), 'nombre' => 'Primero', 'cupo' => 3]],
                'campos' => [], 'privacidad' => 'Autorización de datos para pruebas de matrícula del colegio.',
                'documentos' => [['key' => 'identidad', 'nombre' => 'Identificación', 'instrucciones' => 'Legible', 'obligatorio' => true, 'formatos' => ['png'], 'grados' => []]]]];
    }

    private function staff(?User $user = null): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken(($user ?? $this->rector)->createToken('test')->plainTextToken);
    }

    private function saveSchoolSettings(?int $min = null, ?int $max = null, ?string $notice = null): array
    {
        $this->staff();
        $settings = $this->getJson($this->base.'/ingreso/configuracion')->assertOk()->json('data');
        $payload = ['version' => $settings['version'],
            'privacidad' => $notice ?? 'Aviso institucional de privacidad para todas las matrículas del colegio.',
            'grados' => array_map(fn ($g) => ['url_token' => $g['url_token'], 'edad_min' => $min, 'edad_max' => $max], $settings['grados'])];

        return $this->putJson($this->base.'/ingreso/configuracion', $payload)->assertOk()->json('data');
    }

    public function test_school_enrollment_settings_persist_across_years_and_campaigns(): void
    {
        $initial = $this->saveSchoolSettings(6, 8);
        $this->assertSame(1, $initial['version']);
        $this->assertStringNotContainsString('"id"', json_encode($initial));
        $this->assertStringNotContainsString('_id"', json_encode($initial));
        $this->grade->update(['nombre' => 'Primer grado']);
        $otherYear = $this->year->replicate();
        $otherYear->nombre = '2027';
        $otherYear->save();
        $level = $this->grade->nivel->replicate();
        $level->ano_lectivo_id = $otherYear->id;
        $level->save();
        $grade = Grado::create(['nombre' => 'Primer grado', 'ano_lectivo_id' => $otherYear->id,
            'nivel_id' => $level->id, 'estado' => 'activo']);
        $this->assertSame($this->grade->fresh()->ingreso_grado_id, $grade->ingreso_grado_id);
        $this->assertSame(1, DB::table('ingreso_grados')->count());
        $catalogGrade = collect($this->getJson($this->base.'/ingreso/catalogo')->assertOk()->json('grados'))
            ->firstWhere('token', OpaqueUrlToken::for('grado', $grade->id));
        $this->assertSame($initial['grados'][0]['url_token'], $catalogGrade['regla_token']);
        $this->assertNotSame($catalogGrade['token'], $catalogGrade['regla_token']);
        $replica = $grade->replicate();
        $replica->nombre = 'Primero equivalente';
        $replica->save();
        $this->assertSame($grade->ingreso_grado_id, $replica->ingreso_grado_id);
        $payload = $this->configuration();
        $payload['ano_token'] = OpaqueUrlToken::for('ano-lectivo', $otherYear->id);
        $payload['configuracion']['grados'][0]['token'] = OpaqueUrlToken::for('grado', $grade->id);
        unset($payload['configuracion']['privacidad']);
        $response = $this->postJson($this->base.'/ingreso/campanas', $payload)->assertOk();
        $response->assertJsonPath('data.configuracion.grados.0.edad_min', 6)
            ->assertJsonPath('data.configuracion.privacidad', $initial['privacidad']);
        $this->assertArrayNotHasKey('edad_corte', $response->json('data.configuracion.grados.0'));
        $this->assertArrayNotHasKey('edad_min', Campana::latest('id')->first()->configuracion['grados'][0]);
        $this->saveSchoolSettings(7, 9);
        $this->visitor();
        $this->getJson($this->base.'/ingreso-publico/campanas/'.$this->campaignToken)->assertOk()
            ->assertJsonPath('data.configuracion.grados.0.edad_min', 7);
        $this->getJson($this->base.'/ingreso-publico/campanas/'.$response->json('data.url_token'))->assertOk()
            ->assertJsonPath('data.configuracion.grados.0.edad_max', 9);
    }

    public function test_school_settings_validate_permission_plan_ranges_version_and_opaque_scope(): void
    {
        $settings = $this->saveSchoolSettings();
        $payload = ['version' => $settings['version'], 'privacidad' => $settings['privacidad'],
            'grados' => [['url_token' => $settings['grados'][0]['url_token'], 'edad_min' => 10, 'edad_max' => 8]]];
        $this->putJson($this->base.'/ingreso/configuracion', $payload)->assertUnprocessable()->assertJsonValidationErrors('grados.0.edad_max');
        $payload['grados'][0]['edad_min'] = null;
        $this->putJson($this->base.'/ingreso/configuracion', $payload)->assertUnprocessable();
        $payload['grados'][0]['edad_max'] = null;
        $original = $payload;
        $payload['grados'][0]['url_token'] = (string) $this->grade->id;
        $this->putJson($this->base.'/ingreso/configuracion', $payload)->assertUnprocessable();
        $payload['grados'][0]['url_token'] = OpaqueUrlToken::for('grado', $this->grade->id);
        $this->putJson($this->base.'/ingreso/configuracion', $payload)->assertNotFound();
        $other = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'other-policy-test', 'slug' => 'other-policy', 'name' => 'Other school', 'status' => 'active']));
        tenancy()->initialize($other);
        $payload['grados'][0]['url_token'] = OpaqueUrlToken::for('ingreso-grado', $this->grade->ingreso_grado_id);
        tenancy()->initialize($this->school);
        $this->putJson($this->base.'/ingreso/configuracion', $payload)->assertNotFound();
        $this->putJson($this->base.'/ingreso/configuracion', $original)->assertOk();
        $this->putJson($this->base.'/ingreso/configuracion', $original)->assertConflict();
        $user = User::create(['name' => 'Sin permiso', 'email' => 'policy-viewer@test.test', 'password' => 'TestPassword123!', 'role' => 'secretaria', 'status' => 'active']);
        $this->staff($user);
        $this->getJson($this->base.'/ingreso/configuracion')->assertForbidden();
        $this->putJson($this->base.'/ingreso/configuracion', $original)->assertForbidden();
        $user->givePermissionTo('ingreso.configurar');
        $this->staff($user->fresh());
        $this->getJson($this->base.'/ingreso/configuracion')->assertOk();
        Plan::where('key', 'esencial')->update(['features' => []]);
        $this->getJson($this->base.'/ingreso/configuracion')->assertForbidden();
    }

    public function test_age_uses_school_today_and_corrections_preserve_the_validated_birthday(): void
    {
        // It is still February 13 in Bogotá; UTC has already reached the birthday.
        $this->travelTo(CarbonImmutable::parse('2027-02-14 04:59:00', 'UTC'));
        Campana::first()->update(['desde' => '2026-01-01', 'hasta' => null,
            'configuracion' => [...Campana::first()->configuracion, 'pin_expira_en' => null]]);
        $this->saveSchoolSettings(8, 8);
        $this->start();
        $this->upload();
        $this->submit();
        $application = Solicitud::firstOrFail();
        $this->assertSame(8, $application->validacion_edad['edad']);
        $this->assertSame('2027-02-13', $application->validacion_edad['fecha']);
        $this->assertSame('2018-02-14', $application->validacion_edad['nacimiento']);
        $this->travel(2)->minutes();
        $this->saveSchoolSettings(6, 6);
        $application->update(['estado' => 'correcciones']);
        $this->visitor();
        $this->submissionKey = (string) Str::uuid();
        $this->send()->assertOk()->assertJsonPath('data.validacion_edad.edad', 8);
        $this->assertSame($application->validacion_edad, $application->fresh()->validacion_edad);
        $application->refresh()->update(['estado' => 'correcciones']);
        $this->submissionKey = (string) Str::uuid();
        $data = $this->data();
        $data['nacimiento'] = '2018-02-13';
        $this->send($data)->assertUnprocessable()->assertJsonValidationErrors('datos.nacimiento');
        $this->assertSame('2018-02-14', $application->fresh()->datos['nacimiento']);
        $this->start('after-birthday@test.test');
        $this->upload();
        $this->send()->assertUnprocessable()->assertJsonValidationErrors('datos.nacimiento');
    }

    public function test_changed_privacy_requires_the_displayed_notice_and_preserves_consent_text(): void
    {
        $first = $this->saveSchoolSettings();
        $this->start();
        $this->upload();
        $changed = $this->saveSchoolSettings(null, null, 'Nuevo aviso institucional revisado por el colegio para el tratamiento de datos.');
        $this->visitor();
        $this->send(null, ['privacidad_version' => hash('sha256', $first['privacidad'])])
            ->assertUnprocessable()->assertJsonValidationErrors('privacidad_version');
        $this->assertSame(0, Documento::count());
        $this->assertNull(Solicitud::first()->enviada_en);
        $this->submit();
        $this->assertSame($changed['privacidad'], Solicitud::first()->consentimiento_privacidad['texto']);
        $next = $this->saveSchoolSettings(null, null, 'Una versión posterior del aviso que no reemplaza lo previamente aceptado.');
        $this->assertSame($changed['privacidad'], Solicitud::first()->consentimiento_privacidad['texto']);
        Solicitud::first()->update(['estado' => 'correcciones']);
        $this->visitor();
        $this->submissionKey = (string) Str::uuid();
        $this->submit();
        $history = DB::table('ingreso_envios')->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertSame($changed['privacidad'], json_decode($history[0]->consentimiento_privacidad, true)['texto']);
        $this->assertSame($next['privacidad'], json_decode($history[1]->consentimiento_privacidad, true)['texto']);
        $this->send()->assertOk();
        $this->assertSame(2, DB::table('ingreso_envios')->count());
    }

    public function test_institutional_migration_preserves_legacy_applications_and_flags_conflicting_policies(): void
    {
        $campaign = Campana::firstOrFail();
        $config = $campaign->configuracion;
        $config['grados'][0] += ['edad_min' => 6, 'edad_max' => 9, 'edad_corte' => '2026-01-01'];
        $campaign->update(['configuracion' => $config]);
        $this->start();
        $this->upload();
        $this->submit();
        $original = Solicitud::first()->getRawOriginal();
        unset($original['validacion_edad'], $original['consentimiento_privacidad']);
        $second = $campaign->replicate();
        $otherConfig = $config;
        $otherConfig['privacidad'] = 'Otro aviso de privacidad antiguo que no debe sustituir al primero sin decisión del colegio.';
        $otherConfig['grados'][0]['edad_max'] = 10;
        $second->configuracion = $otherConfig;
        $second->save();
        $migration = require database_path('migrations/tenant/2026_10_14_000001_create_institutional_enrollment_settings.php');
        $migration->down();
        $migration->up();
        $restored = Solicitud::first()->getRawOriginal();
        unset($restored['validacion_edad'], $restored['consentimiento_privacidad']);
        $this->assertSame($original, $restored);
        $this->assertSame($config, $campaign->fresh()->configuracion);
        $this->assertSame('2026-01-01', Solicitud::first()->validacion_edad['fecha']);
        $this->assertSame($config['privacidad'], Solicitud::first()->consentimiento_privacidad['texto']);
        $this->staff();
        $this->getJson($this->base.'/ingreso/configuracion')->assertOk()
            ->assertJsonPath('data.requiere_revision', true)->assertJsonPath('data.privacidad', '')
            ->assertJsonPath('data.grados.0.edad_min', null);
        $present = app(EnrollmentIntake::class)->campaign($campaign->fresh());
        $this->assertSame(9, $present['configuracion']['grados'][0]['edad_max']);
        $this->saveSchoolSettings(6, 8);
        $this->assertSame(8, app(EnrollmentIntake::class)->campaign($campaign->fresh())['configuracion']['grados'][0]['edad_max']);
        $this->assertSame(1, DB::table('ingreso_grados')->count());
    }

    public function test_campaign_rejects_incomplete_fields_and_document_requirements(): void
    {
        $fields = ['nombre', 'ano_token', 'desde', 'hasta', 'configuracion.privacidad', 'configuracion.pin_expira_en',
            'configuracion.grados.0.cupo', 'configuracion.documentos.0.nombre',
            'configuracion.documentos.0.instrucciones',
            'configuracion.documentos.0.formatos'];
        foreach ($fields as $field) {
            $payload = $this->configuration();
            Arr::forget($payload, $field);
            $this->postJson($this->base.'/ingreso/campanas', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach (['nombre', 'configuracion.documentos.0.nombre', 'configuracion.documentos.0.instrucciones'] as $field) {
            $payload = $this->configuration();
            data_set($payload, $field, '   ');
            $this->postJson($this->base.'/ingreso/campanas', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $payload = $this->configuration();
        $payload['configuracion']['documentos'][0]['formatos'] = [];
        $this->postJson($this->base.'/ingreso/campanas', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('configuracion.documentos.0.formatos');
        $payload = $this->configuration();
        $payload['configuracion']['documentos'][0]['instrucciones'] = '';
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('configuracion.documentos.0.instrucciones');
        $this->assertSame(1, Campana::count());
        $this->assertSame('Legible', Campana::firstOrFail()->configuracion['documentos'][0]['instrucciones']);
    }

    public function test_document_size_is_a_single_internal_policy_and_legacy_campaign_field_is_ignored(): void
    {
        $campaign = Campana::firstOrFail();
        $legacy = $campaign->configuracion;
        $legacy['documentos'][0]['max_mb'] = 1;
        $campaign->update(['configuracion' => $legacy]);
        $this->staff();
        $shown = $this->getJson($this->base.'/ingreso/catalogo')->assertOk()->json('campanas.0.configuracion.documentos.0');
        $this->assertArrayNotHasKey('max_mb', $shown);
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $this->configuration())->assertOk();
        $this->assertArrayNotHasKey('max_mb', $campaign->fresh()->configuracion['documentos'][0]);
        $this->assertSame(1000000000, config('enrollment.max_file_bytes'));
        $this->assertLessThanOrEqual(1000000000, \App\Services\Ingreso\EnrollmentSubmission::limits()['max_file_bytes']);
        $this->assertSame(1000000000, config('enrollment.max_submission_bytes'));
        $this->assertLessThanOrEqual(1000000000, \App\Services\Ingreso\EnrollmentSubmission::limits()['max_submission_bytes']);
    }

    public function test_open_ended_campaign_persists_and_can_be_paused_without_losing_follow_up(): void
    {
        $payload = $this->configuration();
        $payload['hasta'] = null;
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $payload)
            ->assertOk()->assertJsonPath('data.hasta', null);
        $this->assertNull(Campana::firstOrFail()->hasta);
        $this->visitor();
        $this->getJson($this->base.'/ingreso-publico/catalogo')->assertOk()->assertJsonMissingPath('campanas');
        $this->getJson($this->base.'/ingreso-publico/campanas/'.$this->campaignToken)->assertOk()->assertJsonPath('data.hasta', null);
        $this->start();
        $this->putJson($this->base.'/ingreso-publico/solicitud', ['datos' => $this->data(), 'enviar' => false])->assertUnprocessable();
        $this->staff();
        $payload['abierta'] = false;
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $payload)->assertOk();
        $this->visitor();
        $this->getJson($this->base.'/ingreso-publico/solicitud')->assertOk();
        $this->putJson($this->base.'/ingreso-publico/solicitud', ['datos' => $this->data(), 'enviar' => false])->assertUnprocessable();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [])->assertUnprocessable();
        $this->staff();
        $payload['abierta'] = true;
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $payload)->assertOk();
        $this->visitor();
        $this->putJson($this->base.'/ingreso-publico/solicitud', ['datos' => $this->data(), 'enviar' => false])->assertUnprocessable();
        $this->assertSame(1, Solicitud::count());
    }

    public function test_campaign_can_accept_applications_without_its_own_grade_capacity(): void
    {
        $payload = $this->configuration();
        $payload['configuracion']['grados'][0]['cupo'] = null;
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $payload)
            ->assertOk()->assertJsonPath('data.configuracion.grados.0.cupo', null);
        $this->assertNull(Campana::firstOrFail()->configuracion['grados'][0]['cupo']);

        $this->start();
        $doc = $this->upload();
        $this->submit();
        $this->staff();
        $this->review($doc, 'aprobado')->assertOk();
        $this->approve()->assertOk();
    }

    public function test_intake_messages_follow_request_language_without_translating_school_data(): void
    {
        $previous = app()->getLocale();
        $campaign = Campana::firstOrFail();
        $campaign->update(['abierta' => false]);
        $this->visitor();
        foreach (['en-US,en;q=0.9' => 'This enrollment round is not open for new submissions.',
            'es-CO,es;q=0.9' => 'La convocatoria no está abierta para nuevas cargas.'] as $locale => $message) {
            $this->withHeader('Accept-Language', $locale)
                ->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [])
                ->assertUnprocessable()->assertJsonPath('message', $message);
            $this->getJson($this->base.'/ingreso-publico/catalogo')->assertOk()->assertJsonMissingPath('campanas');
            $this->getJson($this->base.'/ingreso-publico/campanas/'.$this->campaignToken)->assertOk()
                ->assertJsonPath('data.nombre', 'Ingreso 2026')
                ->assertJsonPath('data.configuracion.documentos.0.nombre', 'Identificación');
            $this->assertSame($previous, app()->getLocale());
        }
        $this->staff();
        $this->withHeader('Accept-Language', 'en')->postJson($this->base.'/ingreso/campanas', [])
            ->assertUnprocessable()->assertJsonValidationErrors('nombre')
            ->assertJsonPath('errors.nombre.0', 'The name field is required.');
    }

    public function test_campaign_dates_remain_validated_with_optional_closure(): void
    {
        $payload = $this->configuration();
        foreach (['2026-02-30', 'not-a-date', today()->subDays(2)->toDateString()] as $date) {
            $payload['hasta'] = $date;
            $this->postJson($this->base.'/ingreso/campanas', $payload)->assertUnprocessable()->assertJsonValidationErrors('hasta');
        }
        $payload['hasta'] = null;
        $this->postJson($this->base.'/ingreso/campanas', $payload)->assertOk()->assertJsonPath('data.hasta', null);
        $this->visitor();
        $start = fn () => $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'dates@example.test', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id)]);
        $campaign = Campana::firstOrFail();
        $campaign->update(['desde' => today()->addDay()->toDateString(), 'hasta' => null]);
        $start()->assertUnprocessable();
        $campaign->update(['desde' => today()->subDays(2)->toDateString(), 'hasta' => today()->subDay()->toDateString()]);
        $start()->assertUnprocessable();
        $campaign->update(['hasta' => now('America/Bogota')->toDateString()]);
        $start()->assertAccepted();
        $campaign->update(['hasta' => null]);
        $this->year->update(['estado' => 'cerrado']);
        $start()->assertUnprocessable();
    }

    public function test_fixed_pin_deadline_is_configurable_without_changing_existing_applications(): void
    {
        $payload = $this->configuration();
        $payload['configuracion']['pin_expira_en'] = now('America/Bogota')->subMinute()->format('Y-m-d\TH:i');
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $payload)->assertUnprocessable();

        $firstDeadline = now('America/Bogota')->addDay()->startOfMinute();
        $payload['configuracion']['pin_expira_en'] = $firstDeadline->format('Y-m-d\TH:i');
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $payload)
            ->assertOk()->assertJsonPath('data.configuracion.pin_expira_en', $payload['configuracion']['pin_expira_en']);
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'first-deadline@test.test', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertAccepted();
        $first = Solicitud::where('email', 'first-deadline@test.test')->firstOrFail();
        $this->assertSame($firstDeadline->timestamp, $first->pin_expira->timestamp);
        $this->assertStringContainsString('Vence el '.$firstDeadline->format('d/m/Y H:i').' (America/Bogota)',
            Notificacion::latest('id')->firstOrFail()->contenido);
        $notice = Notificacion::latest('id')->firstOrFail();
        $rendered = app(EnrollmentMail::class)->data($notice->asunto, $notice->contenido, $notice->clave);
        $this->assertStringContainsString('Vence el '.$firstDeadline->format('d/m/Y H:i'), implode(' ', $rendered['lines']));

        $this->staff();
        $secondDeadline = now('America/Bogota')->addDays(2)->startOfMinute();
        $payload['configuracion']['pin_expira_en'] = $secondDeadline->format('Y-m-d\TH:i');
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $payload)->assertOk();
        $this->assertSame($firstDeadline->timestamp, $first->fresh()->pin_expira->timestamp);
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'second-deadline@test.test', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertAccepted();
        $this->assertSame($secondDeadline->timestamp, Solicitud::where('email', 'second-deadline@test.test')->firstOrFail()->pin_expira->timestamp);
        $campaign = Campana::firstOrFail();
        $campaign->update(['configuracion' => [...$campaign->configuracion,
            'pin_expira_en' => now('America/Bogota')->subDay()->format('Y-m-d\TH:i')]]);
        $notices = Notificacion::count();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'expired-deadline@test.test', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertUnprocessable();
        $this->assertSame($notices, Notificacion::count());
    }

    public function test_campaign_without_pin_deadline_keeps_new_pin_valid_without_changing_private_link_security(): void
    {
        $payload = $this->configuration();
        $payload['configuracion']['pin_expira_en'] = null;
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $payload)
            ->assertOk()->assertJsonPath('data.configuracion.pin_expira_en', null);

        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'without-deadline@test.test', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertAccepted();
        $this->assertNull(Solicitud::firstOrFail()->pin_expira);
        $mail = Notificacion::latest('id')->firstOrFail()->contenido;
        $this->assertStringContainsString('no tiene fecha de vencimiento', $mail);
        $this->assertStringNotContainsString('Vence el', $mail);
        preg_match('/PIN es ([0-9]{4})/', $mail, $pin);
        preg_match('/#solicitud=([a-f0-9]{64})/', $mail, $link);

        $this->travel(2)->days();
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $pin[1], 'access' => $link[1]])
            ->assertOk()->assertJsonPath('data.email', 'without-deadline@test.test');
    }

    public function test_legacy_campaign_migration_preserves_previously_sent_pin_and_private_link(): void
    {
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'legacy-pin@test.test', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertAccepted();
        $mail = Notificacion::latest('id')->firstOrFail()->contenido;
        preg_match('/PIN es ([0-9]{4})/', $mail, $pin);
        preg_match('/#solicitud=([a-f0-9]{64})/', $mail, $link);
        $campaign = Campana::firstOrFail();
        $configuration = $campaign->configuracion;
        unset($configuration['pin_expira_en']);
        $campaign->update(['configuracion' => $configuration]);
        Solicitud::firstOrFail()->update(['pin_expira' => now()->subMinute()]);

        (require database_path('migrations/tenant/2026_10_08_000008_make_legacy_enrollment_pins_open_ended.php'))->up();

        $this->assertArrayHasKey('pin_expira_en', $campaign->fresh()->configuracion);
        $this->assertNull($campaign->fresh()->configuracion['pin_expira_en']);
        $this->assertNull(Solicitud::firstOrFail()->pin_expira);
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $pin[1], 'access' => $link[1]])
            ->assertOk()->assertJsonPath('data.email', 'legacy-pin@test.test');
    }

    private function visitor(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withHeader('Origin', 'http://intake.localhost');
        if ($this->cookies) {
            $this->withUnencryptedCookies($this->cookies)->withHeader('X-CSRF-Token', $this->cookies['ingreso_csrf']);
        }
    }

    public function test_pin_mail_uses_immediate_delivery_queue_after_the_request_commits(): void
    {
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'delivery@test.test',
            'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertAccepted();
        Queue::assertPushed(SendEnrollmentNotice::class, fn (SendEnrollmentNotice $job) => $job->connection === 'sync');
    }

    public function test_pin_request_does_not_claim_email_delivery_when_sending_fails(): void
    {
        $realQueue = new QueueManager($this->app);
        $realQueue->addConnector('sync', fn () => new SyncConnector);
        $this->app->instance('queue', $realQueue);
        Queue::swap($realQueue);
        $this->partialMock(SchoolMail::class, function ($mock) {
            $mock->shouldReceive('sendEnrollment')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        });
        $this->visitor();
        $this->withHeader('Accept-Language', 'es');
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'undelivered@test.test',
            'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertStatus(503)->assertSee('No se pudo enviar el PIN');
    }

    public function test_pin_request_confirms_delivery_only_after_mail_is_accepted(): void
    {
        $realQueue = new QueueManager($this->app);
        $realQueue->addConnector('sync', fn () => new SyncConnector);
        $this->app->instance('queue', $realQueue);
        Queue::swap($realQueue);
        $this->partialMock(SchoolMail::class, function ($mock) {
            $mock->shouldReceive('sendEnrollment')->once();
        });
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'delivered@test.test',
            'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertAccepted();
        $notice = Notificacion::latest('id')->firstOrFail();
        $this->assertNotNull($notice->enviada_en);
        $this->assertNull($notice->getRawOriginal('contenido'));
    }

    private function start(string $email = 'student@test.test', ?string $gradeToken = null): void
    {
        $this->submissionKey = (string) Str::uuid();
        $this->pendingFiles = [];
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', ['email' => $email,
            'grado_token' => $gradeToken ?? OpaqueUrlToken::for('grado', $this->grade->id)])->assertAccepted();
        $notice = Notificacion::latest('id')->firstOrFail();
        preg_match('/PIN es ([0-9]{4})/', $notice->contenido, $m);
        preg_match('/#solicitud=([a-f0-9]{64})/', $notice->contenido, $link);
        $this->assertNotEmpty($m[1]);
        $this->assertNotEmpty($link[1]);
        $this->assertStringNotContainsString($m[1], $notice->getRawOriginal('contenido'));
        $this->assertStringNotContainsString($link[1], $notice->getRawOriginal('contenido'));
        $res = $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $m[1], 'access' => $link[1]])->assertOk();
        foreach ($res->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
        $this->applicationToken = $res->json('data.url_token');
        $this->visitor();
    }

    private function data(): array
    {
        return ['primer_nombre' => 'Ana', 'primer_apellido' => 'Pruebas', 'nacimiento' => '2018-02-14',
            'tipo_documento' => 'TI', 'numero_documento' => '123456789', 'telefono' => '+573186037751',
            'direccion' => 'Calle 1 # 2-3', 'pais' => 'CO', 'departamento' => '41', 'municipio' => '41001', 'adicionales' => []];
    }

    private function upload(): string
    {
        $this->submissionKey = (string) Str::uuid();
        // Selection is browser-local; no request or persisted document until submit().
        $this->pendingFiles['identidad'] = UploadedFile::fake()->image('Mi documento.png');

        return OpaqueUrlToken::for('ingreso-documento', (int) Documento::max('id') + 1);
    }

    private function send(?array $data = null, array $extra = [])
    {
        return $this->post($this->base.'/ingreso-publico/solicitud', [...['envio_token' => $this->submissionKey, 'datos' => json_encode($data ?? $this->data()), 'enviar' => '1',
            'privacidad_version' => app(EnrollmentIntake::class)->campaign(Campana::firstOrFail())['privacidad_version'],
            'consentimiento' => '1', 'documentos' => $this->pendingFiles], ...$extra], ['Accept' => 'application/json']);
    }

    private function submit(): void
    {
        $this->send()->assertOk()->assertJsonPath('data.estado', 'enviada');
        $this->pendingFiles = [];
    }

    private function review(string $doc, string $state, ?string $reason = null)
    {
        return $this->putJson($this->base.'/ingreso/solicitudes/'.$this->applicationToken.'/documentos/'.$doc, ['estado' => $state, 'observacion' => $reason]);
    }

    private function approve()
    {
        return $this->postJson($this->base.'/ingreso/solicitudes/'.$this->applicationToken.'/decision', ['estado' => 'aprobada', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id)]);
    }

    public function test_submission_requires_contact_and_address_and_validates_colombian_mobile(): void
    {
        $this->start();
        $this->upload();
        $data = $this->data();
        unset($data['telefono'], $data['direccion']);
        $this->send($data)
            ->assertUnprocessable()->assertJsonValidationErrors(['datos.telefono', 'datos.direccion']);
        $data['telefono'] = '+571234567890';
        $data['direccion'] = 'Calle 1 # 2-3';
        $this->send($data)->assertUnprocessable();
        $data['telefono'] = '+573186037751';
        $this->send($data)->assertOk();
    }

    public function test_final_submission_rejects_whitespace_mismatched_identity_and_invalid_municipality_without_persisting(): void
    {
        $this->start();
        $this->upload();
        $original = Solicitud::first()->datos;
        foreach (['primer_nombre', 'primer_apellido', 'nacimiento', 'numero_documento', 'direccion', 'tipo_documento'] as $field) {
            $this->travel(1)->minutes();
            $data = $this->data();
            $data[$field] = '   ';
            $this->send($data)->assertUnprocessable()->assertJsonValidationErrors('datos.'.$field);
        }
        foreach (['nacimiento' => '2017-02-14', 'numero_documento' => '987654321', 'primer_apellido' => 'Diferente', 'municipio' => '05001'] as $field => $value) {
            $this->travel(1)->minutes();
            $data = $this->data();
            $data[$field] = $value;
            $this->send($data)->assertUnprocessable()->assertJsonValidationErrors('datos.'.$field);
            $this->assertSame($original, Solicitud::first()->datos);
            $this->assertSame('borrador', Solicitud::first()->estado);
            $this->assertSame(0, Documento::count());
            $this->assertSame(0, StoredFile::withTrashed()->count());
            $this->assertCount(0, Storage::disk('tenant')->allFiles());
        }
        $this->submit();
        $this->send()->assertOk(); // Lost-response retry must be a no-op.
        $this->assertSame(1, Documento::count());
        $this->assertSame(1, Notificacion::where('clave', 'like', '%:envio:%')->count());
    }

    public function test_configured_grade_age_and_document_formats_are_enforced_on_the_server(): void
    {
        $this->saveSchoolSettings(6, 9);
        $this->start();
        $this->upload();
        $data = $this->data();
        $data['nacimiento'] = '2022-01-01';
        $this->send($data)->assertUnprocessable()->assertJsonValidationErrors('datos.nacimiento');
        $this->send(null, ['documentos' => ['identidad' => UploadedFile::fake()->image('not-allowed.jpg')]])
            ->assertUnprocessable()->assertJsonValidationErrors('documentos.identidad');
        $this->send(null, ['documentos' => ['identidad' => UploadedFile::fake()->create('renamed.png', 1, 'text/plain')]])
            ->assertUnprocessable()->assertJsonValidationErrors('documentos.identidad');
        $this->assertSame(0, Documento::count());
        $this->submit();
    }

    public function test_unreadable_document_rolls_back_every_file_and_preserves_the_previous_application(): void
    {
        $this->partialMock(IdentityDocumentReader::class, function ($mock) {
            $mock->shouldReceive('read')->andReturn('ILEGIBLE');
        });
        $this->start();
        $this->upload();
        $this->send()->assertUnprocessable()->assertJsonValidationErrors('documentos');
        $this->assertSame(0, Documento::count());
        $this->assertSame(0, StoredFile::withTrashed()->count());
        $this->assertCount(0, Storage::disk('tenant')->allFiles());
        $this->assertSame('borrador', Solicitud::first()->estado);
    }

    public function test_geography_catalog_has_all_departments_and_rejects_cross_department_combinations(): void
    {
        $this->visitor();
        $rows = $this->getJson($this->base.'/ingreso-publico/ubicaciones')->assertOk()->json('data');
        $this->assertCount(33, $rows); // 32 departments plus Bogotá D.C.
        $this->assertGreaterThan(1100, collect($rows)->sum(fn ($d) => count($d['municipios'])));
        $this->assertTrue(collect(collect($rows)->firstWhere('codigo', '41')['municipios'])->contains('codigo', '41001'));
    }

    public function test_second_file_scan_failure_removes_the_whole_provisional_batch(): void
    {
        $campaign = Campana::firstOrFail();
        $config = $campaign->configuracion;
        $config['documentos'][] = [...$config['documentos'][0], 'key' => 'certificado', 'nombre' => 'Certificado', 'obligatorio' => false];
        $campaign->update(['configuracion' => $config]);
        $this->app->instance(FileScanner::class, new class implements FileScanner
        {
            private int $calls = 0;

            public function scan(string $disk, string $path): void
            {
                if (++$this->calls === 2) {
                    throw new StorageException('Fixture: escáner rechazó el segundo archivo.');
                }
            }
        });
        $this->start();
        $this->upload();
        $this->pendingFiles['certificado'] = UploadedFile::fake()->image('certificado.png');
        $this->send()->assertUnprocessable()->assertJsonValidationErrors('documentos.certificado');
        $this->assertSame(0, Documento::count());
        $this->assertSame(0, StoredFile::withTrashed()->count());
        $this->assertCount(0, Storage::disk('tenant')->allFiles());
        $this->assertSame('borrador', Solicitud::first()->estado);
    }

    public function test_selection_is_not_saved_and_replacements_are_committed_only_on_submission(): void
    {
        $this->start();
        $old = $this->upload();
        $this->assertSame(0, Documento::count());
        $this->assertCount(0, Storage::disk('tenant')->allFiles());
        $this->submit();
        $this->staff();
        $this->review($old, 'rechazado', 'Sube una copia legible.')->assertOk();
        $this->visitor();
        $this->deleteJson($this->base.'/ingreso-publico/documentos/identidad')->assertStatus(410);
        $this->submissionKey = (string) Str::uuid();
        $this->send(null, ['retirar' => ['identidad']])->assertUnprocessable();
        $new = $this->upload();
        $this->assertSame(1, Documento::count());
        $this->submit();
        $this->assertSame(2, Documento::count());
        $this->assertNotSame($old, $new);
        $this->getJson($this->base.'/ingreso-publico/solicitud')->assertOk()->assertJsonPath('data.documentos.0.version', 2);
        $this->staff();
        $this->review($old, 'aprobado')->assertUnprocessable();
    }

    public function test_full_enrollment_with_corrections_account_and_group_without_guardian(): void
    {
        $this->start();
        $this->assertSame(1, User::count());
        $this->putJson($this->base.'/ingreso-publico/solicitud', ['datos' => $this->data(), 'enviar' => true, 'consentimiento' => true])->assertUnprocessable();
        $doc = $this->upload();
        $this->submit();
        $this->staff();
        $this->approve()->assertUnprocessable();
        $this->review($doc, 'rechazado')->assertUnprocessable();
        $this->review($doc, 'rechazado', 'La imagen está borrosa.')->assertOk()->assertJsonPath('data.estado', 'correcciones');
        $this->visitor();
        $this->getJson($this->base.'/ingreso-publico/solicitud')->assertOk()->assertJsonPath('data.documentos.0.observacion', 'La imagen está borrosa.');
        $new = $this->upload();
        $this->submit();
        $this->assertSame(2, Documento::count());
        $this->staff();
        $this->review($doc, 'aprobado')->assertUnprocessable();
        $this->review($new, 'aprobado')->assertOk();
        $this->approve()->assertOk()->assertJsonPath('data.estado', 'aprobada');
        $this->approve()->assertOk();
        $this->assertSame(2, User::count());
        $this->assertSame(1, User::role('estudiante')->count());
        $this->assertSame(0, Matricula::count());
        $student = User::where('email', 'student@test.test')->firstOrFail();
        $this->assertTrue($student->must_change_password);
        $this->assertTrue(Hash::check($student->temporary_password, $student->password));
        $this->assertFalse(app(TenantOnboarding::class)->status($student)['institution_required']);
        $this->assertSame(1, Notificacion::where('clave', 'like', '%:credenciales')->count());
        $res = $this->postJson($this->base.'/ingreso/campanas/'.$this->campaignToken.'/distribuir', ['confirmar' => false])->assertOk();
        $pairs = $res->json('asignaciones');
        $this->assertCount(1, $pairs);
        $this->assertSame(0, Matricula::count());
        $this->postJson($this->base.'/ingreso/campanas/'.$this->campaignToken.'/distribuir', ['confirmar' => true, 'asignaciones' => $pairs])->assertOk();
        $this->postJson($this->base.'/ingreso/campanas/'.$this->campaignToken.'/distribuir', ['confirmar' => true, 'asignaciones' => $pairs])->assertOk();
        $this->assertSame(1, Matricula::count());
        $this->visitor();
        $response = $this->getJson($this->base.'/ingreso-publico/solicitud')->assertOk()->assertJsonPath('data.estado', 'matriculada');
        $json = $response->getContent();
        $this->assertStringNotContainsString('pin_hash', $json);
        $this->assertStringNotContainsString('archivo_id', $json);
        $this->assertStringNotContainsString('estudiante_id', $json);
    }

    public function test_pin_session_csrf_and_tenant_isolation(): void
    {
        $this->visitor();
        $this->getJson($this->base.'/ingreso-publico/solicitud')->assertUnauthorized();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/acceder', ['email' => 'student@test.test', 'pin' => '123456'])->assertNotFound();
        $this->start();
        $this->withHeader('X-CSRF-Token', 'wrong')->putJson($this->base.'/ingreso-publico/solicitud', ['datos' => $this->data(), 'enviar' => false])->assertStatus(419);
        $this->visitor();
        $this->withHeader('Origin', 'https://hostile.example')->putJson($this->base.'/ingreso-publico/solicitud', [])->assertForbidden();
        $this->visitor();
        $this->getJson($this->base.'/ingreso-publico/documentos/1')->assertNotFound();
        $payload = json_decode(Crypt::decryptString($this->cookies['ingreso_session']), true);
        $payload['school'] = 'another-school';
        $this->withUnencryptedCookies(['ingreso_session' => Crypt::encryptString(json_encode($payload))])
            ->getJson($this->base.'/ingreso-publico/solicitud')->assertUnauthorized();
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/salir')->assertOk();
        $this->getJson($this->base.'/ingreso-publico/solicitud')->assertUnauthorized();
    }

    public function test_four_digit_pin_requires_its_private_link_and_only_opens_its_own_campaign(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->staff();
        $second = $this->configuration();
        $second['nombre'] = 'Ingreso paralelo 2026';
        $secondToken = $this->postJson($this->base.'/ingreso/campanas', $second)->assertOk()->json('data.url_token');
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'first@test.test', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertAccepted();
        $firstMail = Notificacion::latest('id')->firstOrFail()->contenido;
        preg_match('/PIN es ([0-9]{4})/', $firstMail, $first);
        preg_match('/#solicitud=([a-f0-9]{64})/', $firstMail, $firstLink);
        $this->postJson($this->base.'/ingreso-publico/'.$secondToken.'/iniciar', [
            'email' => 'second@test.test', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertAccepted();
        $secondMail = Notificacion::latest('id')->firstOrFail()->contenido;
        preg_match('/PIN es ([0-9]{4})/', $secondMail, $secondPin);
        preg_match('/#solicitud=([a-f0-9]{64})/', $secondMail, $secondLink);
        $this->assertNotSame($firstLink[1], $secondLink[1]);
        $this->assertSame(2, Solicitud::whereNotNull('access_lookup')->count());
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $first[1]])->assertUnprocessable();
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $secondPin[1], 'access' => $firstLink[1]])
            ->assertUnprocessable();
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $secondPin[1], 'access' => $secondLink[1]])
            ->assertOk()->assertJsonPath('data.email', 'second@test.test')->assertJsonPath('data.campana.url_token', $secondToken);
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $first[1], 'access' => $firstLink[1]])
            ->assertOk()->assertJsonPath('data.email', 'first@test.test')->assertJsonPath('data.campana.url_token', $this->campaignToken);
    }

    public function test_permission_and_frozen_campaign_and_upload_validation(): void
    {
        $this->start();
        $this->post($this->base.'/ingreso-publico/documentos/identidad', ['archivo' => UploadedFile::fake()->create('script.php', 1, 'text/x-php')], ['Accept' => 'application/json'])->assertStatus(410);
        $this->staff();
        $payload = $this->configuration();
        $payload['configuracion']['documentos'] = [];
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $payload)->assertUnprocessable();
        $payload = $this->configuration();
        $payload['abierta'] = false;
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $payload)->assertOk();
        $this->visitor();
        $this->putJson($this->base.'/ingreso-publico/solicitud', ['datos' => $this->data(), 'enviar' => false])->assertUnprocessable();
        $user = User::create(['name' => 'Otro', 'email' => 'other@test.test', 'password' => 'Password123!', 'status' => 'active', 'role' => 'docente']);
        $this->staff($user);
        $this->getJson($this->base.'/ingreso/catalogo')->assertForbidden();
        $this->postJson($this->base.'/ingreso/solicitudes/'.$this->applicationToken.'/decision', ['estado' => 'rechazada', 'observacion' => 'No'])->assertForbidden();
    }

    public function test_assignment_rechecks_capacity_after_preview_and_rolls_back(): void
    {
        $this->start();
        $doc = $this->upload();
        $this->submit();
        $this->staff();
        $this->review($doc, 'aprobado')->assertOk();
        $this->approve()->assertOk();
        $pairs = $this->postJson($this->base.'/ingreso/campanas/'.$this->campaignToken.'/distribuir', ['confirmar' => false])->assertOk()->json('asignaciones');
        $this->group->update(['cupo_maximo' => 0]);
        $this->postJson($this->base.'/ingreso/campanas/'.$this->campaignToken.'/distribuir', ['confirmar' => true, 'asignaciones' => $pairs])->assertUnprocessable();
        $this->assertSame(0, Matricula::count());
        $this->assertSame('aprobada', Solicitud::first()->estado);
    }

    public function test_files_cannot_be_read_from_another_application(): void
    {
        $this->start();
        $firstDoc = $this->upload();
        $this->submit();
        $firstApplication = $this->applicationToken;
        $this->start('second@test.test');
        $secondDoc = $this->upload();
        $this->submit();
        $this->getJson($this->base.'/ingreso-publico/documentos/'.$firstDoc)->assertNotFound();
        $this->get($this->base.'/ingreso-publico/documentos/'.$secondDoc)->assertOk();
        $this->staff();
        $this->get($this->base.'/ingreso/solicitudes/'.$firstApplication.'/documentos/'.$secondDoc, ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson($this->base.'/ingreso/solicitudes/'.$firstApplication)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_reissuing_a_pin_for_the_same_email_replaces_it_without_creating_another_request(): void
    {
        $this->start();
        $firstMail = Notificacion::latest('id')->first()->contenido;
        preg_match('/PIN es ([0-9]{4})/', $firstMail, $m);
        preg_match('/#solicitud=([a-f0-9]{64})/', $firstMail, $firstLink);
        Solicitud::first()->update(['pin_expira' => now()->subMinute()]);
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/recuperar', ['email' => 'student@test.test'])->assertNotFound();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'student@test.test', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertAccepted();
        $newMail = Notificacion::latest('id')->first()->contenido;
        preg_match('/PIN es ([0-9]{4})/', $newMail, $new);
        preg_match('/#solicitud=([a-f0-9]{64})/', $newMail, $newLink);
        $this->assertNotSame($firstLink[1], $newLink[1]);
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $m[1], 'access' => $firstLink[1]])->assertUnprocessable();
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $new[1], 'access' => $newLink[1]])->assertOk();
        $this->assertSame(1, Solicitud::count());
        $this->assertSame(1, User::count());
    }

    public function test_short_pin_expires_and_five_wrong_attempts_lock_the_link(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'locked@test.test', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertAccepted();
        $mail = Notificacion::latest('id')->firstOrFail()->contenido;
        preg_match('/PIN es ([0-9]{4})/', $mail, $pin);
        preg_match('/#solicitud=([a-f0-9]{64})/', $mail, $link);
        $wrong = $pin[1] === '0000' ? '0001' : '0000';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $wrong, 'access' => $link[1]])->assertUnprocessable();
        }
        $this->assertSame(5, Solicitud::firstOrFail()->pin_intentos);
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $pin[1], 'access' => $link[1]])->assertUnprocessable();
        Solicitud::firstOrFail()->update(['pin_intentos' => 0, 'pin_expira' => now()->subSecond()]);
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $pin[1], 'access' => $link[1]])->assertUnprocessable();
    }

    public function test_short_pin_can_use_the_http_only_browser_challenge_but_not_the_pin_alone(): void
    {
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', [
            'email' => 'browser@test.test', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id),
        ])->assertAccepted()->assertCookie('ingreso_challenge');
        $mail = Notificacion::latest('id')->firstOrFail()->contenido;
        preg_match('/PIN es ([0-9]{4})/', $mail, $pin);
        preg_match('/#solicitud=([a-f0-9]{64})/', $mail, $link);
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $pin[1]])->assertUnprocessable();
        $this->withUnencryptedCookies(['ingreso_challenge' => $link[1]])
            ->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $pin[1]])->assertOk();
    }

    public function test_long_pins_and_the_old_login_route_are_not_accepted(): void
    {
        $this->start();
        $longCode = strtoupper(bin2hex(random_bytes(16)));
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/consultar', ['pin' => $longCode])
            ->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/acceder', [
            'email' => 'student@test.test', 'pin' => $longCode,
        ])->assertNotFound();
        $this->assertFalse(Schema::hasColumn('ingreso_solicitudes', 'pin_lookup'));
    }

    public function test_corrections_must_be_resubmitted_before_approval(): void
    {
        $this->start();
        $doc = $this->upload();
        $this->submit();
        $this->staff();
        $this->review($doc, 'aprobado')->assertOk();
        $this->postJson($this->base.'/ingreso/solicitudes/'.$this->applicationToken.'/decision', ['estado' => 'correcciones', 'observacion' => 'Completa el nombre.'])->assertOk();
        $this->visitor();
        $this->putJson($this->base.'/ingreso-publico/solicitud', ['datos' => ['adicionales' => []], 'enviar' => false])->assertUnprocessable();
        $this->staff();
        $this->approve()->assertUnprocessable();
        $this->assertSame(1, User::count());
        $this->visitor();
        $this->submissionKey = (string) Str::uuid();
        $this->submit();
        $this->staff();
        $this->approve()->assertOk();
    }

    public function test_grade_change_requires_new_documents_and_explicit_permission(): void
    {
        $other = Grado::create(['ano_lectivo_id' => $this->year->id, 'nivel_id' => $this->grade->nivel_id, 'nombre' => 'Segundo', 'estado' => 'activo']);
        $otherToken = OpaqueUrlToken::for('grado', $other->id);
        $c = Campana::first();
        $config = $c->configuracion;
        $config['grados'][] = ['token' => $otherToken, 'nombre' => 'Segundo', 'cupo' => 3];
        $config['documentos'][] = ['key' => 'certificado', 'nombre' => 'Certificado', 'instrucciones' => '', 'obligatorio' => true, 'formatos' => ['png'], 'grados' => [$otherToken]];
        $c->update(['configuracion' => $config]);
        $this->start();
        $doc = $this->upload();
        $this->submit();
        $this->staff();
        $this->review($doc, 'aprobado')->assertOk();
        $data = ['estado' => 'aprobada', 'grado_token' => $otherToken, 'observacion' => 'Grado recomendado.'];
        $this->postJson($this->base.'/ingreso/solicitudes/'.$this->applicationToken.'/decision', $data)->assertUnprocessable();
        Role::findByName('rector', 'web')->revokePermissionTo('ingreso.cambiar_grado');
        $this->staff($this->rector->fresh());
        $this->postJson($this->base.'/ingreso/solicitudes/'.$this->applicationToken.'/decision', $data)->assertForbidden();
        $this->assertSame(1, User::count());
    }

    public function test_plan_limit_and_duplicate_identity_block_account_creation(): void
    {
        $this->start();
        $doc = $this->upload();
        $this->submit();
        $this->staff();
        $this->review($doc, 'aprobado')->assertOk();
        Plan::where('key', 'esencial')->update(['max_estudiantes' => 0]);
        $this->approve()->assertUnprocessable();
        $this->assertSame(1, User::count());
        Plan::where('key', 'esencial')->update(['max_estudiantes' => 300]);
        $this->approve()->assertOk();
        $this->start('second@test.test');
        $this->upload();
        $this->send()->assertUnprocessable()->assertJsonValidationErrors('datos.numero_documento');
        $this->assertSame(1, Documento::count());
        $this->assertSame(2, User::count());
        Plan::where('key', 'esencial')->update(['features' => []]);
        $this->staff();
        $this->getJson($this->base.'/ingreso/catalogo')->assertForbidden();
        $this->visitor();
        $this->getJson($this->base.'/ingreso-publico/catalogo')->assertForbidden();
    }

    public function test_verified_renewal_prefills_and_reuses_student_without_overwriting_old_enrollment(): void
    {
        $priorYear = AnoLectivo::create(['nombre' => '2025', 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2025-12-31',
            'num_periodos' => 4, 'tipo_calendario' => 'A', 'estado' => 'cerrado']);
        $priorLevel = Nivel::create(['ano_lectivo_id' => $priorYear->id, 'nombre' => 'Primaria', 'nivel_educativo' => 'primaria', 'estado' => 'activo']);
        $first = Grado::create(['ano_lectivo_id' => $priorYear->id, 'nivel_id' => $priorLevel->id, 'nombre' => 'Primero', 'estado' => 'activo']);
        $second = Grado::create(['ano_lectivo_id' => $priorYear->id, 'nivel_id' => $priorLevel->id, 'nombre' => 'Segundo', 'estado' => 'activo']);
        $priorGroup = Grupo::create(['ano_lectivo_id' => $priorYear->id, 'grado_id' => $first->id, 'nombre' => '01A', 'estado' => 'activo']);
        $student = User::create(['name' => 'Ana Pruebas', 'email' => 'renewal@test.test', 'password' => 'OldPassword123!', 'role' => 'estudiante', 'status' => 'active']);
        $student->assignRole('estudiante');
        $originalHash = $student->password;
        $oldData = [...$this->data(), 'direccion' => 'Dirección anterior'];
        DB::table('ingreso_perfiles')->insert(['estudiante_id' => $student->id, 'tipo_documento' => 'TI',
            'numero_documento' => '123456789', 'datos' => json_encode($oldData), 'created_at' => now(), 'updated_at' => now()]);
        $oldEnrollment = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $priorGroup->id,
            'ano_lectivo_id' => $priorYear->id, 'estado' => 'activa']);
        PromocionAcademica::create(['ano_lectivo_id' => $priorYear->id, 'matricula_id' => $oldEnrollment->id,
            'propuesta' => 'promovido', 'resultado' => 'promovido', 'grado_destino_id' => $second->id,
            'insumos' => [], 'huella' => str_repeat('a', 64), 'motivo' => 'Resultado académico',
            'reviewed_by' => $this->rector->id, 'approved_at' => now(), 'version' => 1]);
        $currentFirst = $this->grade;
        $this->grade = Grado::create(['ano_lectivo_id' => $this->year->id, 'nivel_id' => $this->grade->nivel_id,
            'nombre' => 'Segundo', 'estado' => 'activo']);
        $this->group = Grupo::create(['ano_lectivo_id' => $this->year->id, 'grado_id' => $this->grade->id,
            'nombre' => '02A', 'estado' => 'activo', 'cupo_maximo' => 2]);
        $configuration = $this->configuration();
        $configuration['configuracion']['grados'][] = ['token' => OpaqueUrlToken::for('grado', $currentFirst->id), 'nombre' => 'Primero', 'cupo' => 3];
        $this->campaignToken = $this->postJson($this->base.'/ingreso/campanas', $configuration)->assertOk()->json('data.url_token');
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/solicitud/renovacion', ['tipo_documento' => 'TI',
            'numero_documento' => '123456789'])->assertUnauthorized();
        $this->start('renewal@test.test', OpaqueUrlToken::for('grado', $currentFirst->id));
        $this->postJson($this->base.'/ingreso-publico/solicitud/renovacion', ['tipo_documento' => 'TI',
            'numero_documento' => '123456789'])->assertOk()->assertJsonPath('data.encontrado', true)
            ->assertJsonPath('data.grado_token', OpaqueUrlToken::for('grado', $this->grade->id))
            ->assertJsonPath('data.datos.direccion', 'Dirección anterior');
        $this->upload();
        $this->submit();
        $this->assertSame($student->id, Solicitud::latest('id')->first()->estudiante_id);
        $this->staff();
        $doc = OpaqueUrlToken::for('ingreso-documento', Documento::latest('id')->first()->id);
        $this->review($doc, 'aprobado')->assertOk();
        $this->approve()->assertOk()->assertJsonPath('data.renovacion', true);
        $this->assertSame(2, User::count());
        $this->assertSame($originalHash, $student->fresh()->password);
        $this->assertSame(1, Matricula::count());
        $this->assertSame('Calle 1 # 2-3', json_decode(DB::table('ingreso_perfiles')->where('estudiante_id', $student->id)->value('datos'), true)['direccion']);
        $pairs = $this->postJson($this->base.'/ingreso/campanas/'.$this->campaignToken.'/distribuir', ['confirmar' => false])->assertOk()->json('asignaciones');
        $this->postJson($this->base.'/ingreso/campanas/'.$this->campaignToken.'/distribuir', ['confirmar' => true, 'asignaciones' => $pairs])->assertOk();
        $this->assertSame(2, Matricula::where('estudiante_id', $student->id)->count());
        $this->assertSame($oldEnrollment->id, Matricula::where('estudiante_id', $student->id)->where('ano_lectivo_id', $priorYear->id)->value('id'));
        $this->assertSame($this->grade->id, Matricula::where('estudiante_id', $student->id)->where('ano_lectivo_id', $this->year->id)->first()->grupo->grado_id);
    }

    public function test_failed_year_renews_into_same_grade_only_after_approved_result_and_never_duplicates_the_student(): void
    {
        $priorYear = AnoLectivo::create(['nombre' => '2025', 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2025-12-31',
            'num_periodos' => 4, 'tipo_calendario' => 'A', 'estado' => 'cerrado']);
        $priorLevel = Nivel::create(['ano_lectivo_id' => $priorYear->id, 'nombre' => 'Primaria', 'nivel_educativo' => 'primaria', 'estado' => 'activo']);
        $priorGrade = Grado::create(['ano_lectivo_id' => $priorYear->id, 'nivel_id' => $priorLevel->id, 'nombre' => 'Primero', 'estado' => 'activo']);
        $priorGroup = Grupo::create(['ano_lectivo_id' => $priorYear->id, 'grado_id' => $priorGrade->id, 'nombre' => '01A', 'estado' => 'activo']);
        $student = User::create(['name' => 'Ana Pruebas', 'email' => 'repite@test.test', 'password' => 'OldPassword123!',
            'role' => 'estudiante', 'status' => 'active']);
        $student->assignRole('estudiante');
        $oldHash = $student->password;
        DB::table('ingreso_perfiles')->insert(['estudiante_id' => $student->id, 'tipo_documento' => 'TI',
            'numero_documento' => '123456789', 'datos' => json_encode($this->data()), 'created_at' => now(), 'updated_at' => now()]);
        $previous = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $priorGroup->id,
            'ano_lectivo_id' => $priorYear->id, 'estado' => 'activa']);
        // Closing a year never marks a non-renewing student as departed.
        $this->assertSame('active', $student->fresh()->status);
        $this->start($student->email);
        $identity = ['tipo_documento' => 'TI', 'numero_documento' => '123456789'];
        $this->postJson($this->base.'/ingreso-publico/solicitud/renovacion', $identity)
            ->assertUnprocessable()->assertJsonValidationErrors('datos.numero_documento');
        $this->assertSame(1, User::where('email', $student->email)->count());
        PromocionAcademica::create(['ano_lectivo_id' => $priorYear->id, 'matricula_id' => $previous->id,
            'propuesta' => 'reprobado', 'resultado' => 'reprobado', 'grado_destino_id' => null,
            'insumos' => [], 'huella' => str_repeat('b', 64), 'motivo' => 'Resultado académico',
            'reviewed_by' => $this->rector->id, 'approved_at' => now(), 'version' => 1]);
        $this->postJson($this->base.'/ingreso-publico/solicitud/renovacion', $identity)
            ->assertOk()->assertJsonPath('data.encontrado', true)
            ->assertJsonPath('data.grado_token', OpaqueUrlToken::for('grado', $this->grade->id));
        $this->upload();
        $this->submit();
        $this->staff();
        $this->review(OpaqueUrlToken::for('ingreso-documento', Documento::latest('id')->firstOrFail()->id), 'aprobado')->assertOk();
        $this->approve()->assertOk()->assertJsonPath('data.renovacion', true);
        $pairs = $this->postJson($this->base.'/ingreso/campanas/'.$this->campaignToken.'/distribuir', ['confirmar' => false])
            ->assertOk()->json('asignaciones');
        $this->postJson($this->base.'/ingreso/campanas/'.$this->campaignToken.'/distribuir',
            ['confirmar' => true, 'asignaciones' => $pairs])->assertOk();
        $this->assertSame($oldHash, $student->fresh()->password);
        $this->assertSame('active', $student->fresh()->status);
        $this->assertSame(2, Matricula::where('estudiante_id', $student->id)->count());
        $this->assertSame($previous->id, Matricula::where('estudiante_id', $student->id)
            ->where('ano_lectivo_id', $priorYear->id)->value('id'));
        $this->assertSame($this->grade->id, Matricula::where('estudiante_id', $student->id)
            ->where('ano_lectivo_id', $this->year->id)->firstOrFail()->grupo->grado_id);

        try {
            app(\App\Services\Ingreso\EnrollmentRenewal::class)->find(Solicitud::where('estudiante_id', $student->id)->firstOrFail(), 'TI', '123456789');
            $this->fail('A second enrollment in the same academic year must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('datos.numero_documento', $e->errors());
        }
        $this->assertSame(1, User::where('email', $student->email)->count());
    }

    public function test_temporary_password_expires_and_notification_is_not_sent_twice(): void
    {
        $this->start();
        $doc = $this->upload();
        $this->submit();
        $this->staff();
        $this->review($doc, 'aprobado')->assertOk();
        $this->approve()->assertOk();
        $student = User::where('email', 'student@test.test')->firstOrFail();
        $student->forceFill(['temporary_password_expires_at' => now()->subMinute()])->save();
        $this->visitor();
        $this->postJson($this->base.'/login', ['email' => $student->email, 'password' => $student->temporary_password])->assertUnprocessable();
        $notice = Notificacion::where('clave', 'like', '%:credenciales')->firstOrFail();
        $this->partialMock(SchoolMail::class, function ($mock) {
            $mock->shouldReceive('sendEnrollment')->once();
        });
        $job = new SendEnrollmentNotice((string) $this->school->id, $notice->id);
        $job->handle();
        $job->handle();
        $this->assertNotNull($notice->fresh()->enviada_en);
        $this->assertNull($notice->fresh()->getRawOriginal('contenido'));
    }

    public function test_nested_sede_link_uses_full_frontend_hostname(): void
    {
        $child = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'intake-sede', 'slug' => 'norte', 'name' => 'Sede Norte',
            'parent_id' => $this->school->id, 'tipo' => 'sede', 'status' => 'active', 'plan' => 'esencial']));
        $child->domains()->create(['domain' => 'norte.intake']);
        config(['frontend.url' => 'https://colegios.example']);
        $link = $child->run(fn () => app(EnrollmentIntake::class)->baseUrl());
        $this->assertSame('https://norte.intake.colegios.example', $link);
    }

    public function test_gmail_settings_are_tested_encrypted_and_not_exposed(): void
    {
        $this->rector->givePermissionTo('config.correo');
        $this->staff();
        $this->partialMock(SchoolMail::class, function ($mock) {
            $mock->shouldReceive('test')->once()->withArgs(fn ($s) => $s->email === 'colegio@gmail.com' && $s->app_password === 'abcdefghijklmnop');
        });
        $response = $this->putJson($this->base.'/correo-institucional', ['email' => 'colegio@gmail.com', 'nombre' => 'Colegio', 'app_password' => 'abcd efgh ijkl mnop'])
            ->assertOk()->assertJsonPath('configurado', true);
        $this->assertStringNotContainsString('app_password', $response->getContent());
        $this->assertStringNotContainsString('abcdefghijklmnop', DB::table('correo_configuracion')->value('app_password'));
        $this->getJson($this->base.'/correo-institucional')->assertOk()->assertJsonMissingPath('app_password');
        $this->getJson($this->base.'/ingreso/catalogo')->assertOk()->assertJsonPath('correo_operativo', true);
        $this->deleteJson($this->base.'/correo-institucional')->assertForbidden();
        $this->approveMail('desconectar');
        $this->deleteJson($this->base.'/correo-institucional')->assertOk()->assertJsonPath('configurado', false);
        $this->assertSame(1, DB::table('correo_configuracion')->count());
        $this->assertNull(DB::table('correo_configuracion')->value('app_password'));
    }

    public function test_gmail_failed_test_keeps_previous_settings_and_denies_non_rectors(): void
    {
        CorreoConfiguracion::create(['key' => 'gmail', 'email' => 'old@gmail.com', 'nombre' => 'Colegio', 'app_password' => 'abcdefghijklmnop', 'verificado_en' => now()]);
        $this->approveMail('editar');
        $this->rector->givePermissionTo('config.correo');
        $this->staff();
        $this->partialMock(SchoolMail::class, function ($mock) {
            $mock->shouldReceive('test')->once()->andThrow(new \RuntimeException('Prueba rechazada.'));
        });
        $this->putJson($this->base.'/correo-institucional', ['email' => 'new@gmail.com', 'nombre' => 'Colegio', 'app_password' => 'ponmlkjihgfedcba'])->assertUnprocessable();
        $this->assertSame('old@gmail.com', DB::table('correo_configuracion')->value('email'));
        $this->rector->revokePermissionTo('config.correo');
        $this->staff($this->rector->fresh());
        $this->getJson($this->base.'/correo-institucional')->assertForbidden();
        $this->deleteJson($this->base.'/correo-institucional')->assertForbidden();
    }

    public function test_gmail_transports_are_fresh_fixed_host_and_do_not_mutate_global_mailer(): void
    {
        $default = config('mail.default');
        $mail = app(SchoolMail::class);
        $a = new CorreoConfiguracion(['email' => 'one@gmail.com', 'nombre' => 'Uno', 'app_password' => 'abcdefghijklmnop']);
        $b = new CorreoConfiguracion(['email' => 'two@gmail.com', 'nombre' => 'Dos', 'app_password' => 'ponmlkjihgfedcba']);
        $first = $mail->mailer($a);
        $second = $mail->mailer($b);
        $this->assertNotSame($first, $second);
        $this->assertSame('one@gmail.com', $first->getSymfonyTransport()->getUsername());
        $this->assertSame('two@gmail.com', $second->getSymfonyTransport()->getUsername());
        $this->assertSame('smtp.gmail.com', $first->getSymfonyTransport()->getStream()->getHost());
        $this->assertSame(465, $first->getSymfonyTransport()->getStream()->getPort());
        $this->assertSame($default, config('mail.default'));
    }

    public function test_password_reset_notification_uses_school_sender(): void
    {
        $mailer = \Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class);
        $mailer->shouldReceive('send')->once()->andReturn(null);
        $this->partialMock(SchoolMail::class, function ($mock) use ($mailer) {
            $mock->shouldReceive('mailer')->once()->andReturn($mailer);
        });
        $this->rector->sendPasswordResetNotification('example-reset-token');
        $this->assertTrue(true);
    }

    public function test_gmail_can_reuse_secret_only_for_same_sender(): void
    {
        CorreoConfiguracion::create(['key' => 'gmail', 'email' => 'old@gmail.com', 'nombre' => 'Colegio', 'app_password' => 'abcdefghijklmnop', 'verificado_en' => now()]);
        $this->approveMail('editar');
        $this->rector->givePermissionTo('config.correo');
        $this->staff();
        $this->partialMock(SchoolMail::class, function ($mock) {
            $mock->shouldReceive('test')->once()->withArgs(fn ($s) => $s->email === 'old@gmail.com' && $s->app_password === 'abcdefghijklmnop');
        });
        $this->putJson($this->base.'/correo-institucional', ['email' => 'new@gmail.com', 'nombre' => 'Colegio'])->assertUnprocessable();
        $this->putJson($this->base.'/correo-institucional', ['email' => 'old@gmail.com', 'nombre' => 'Nombre nuevo'])->assertOk()->assertJsonMissingPath('app_password');
        $this->assertSame('Nombre nuevo', DB::table('correo_configuracion')->value('nombre'));
    }

    public function test_gmail_cancelled_send_is_not_recorded_as_delivery(): void
    {
        CorreoConfiguracion::create(['key' => 'gmail', 'email' => 'school@gmail.com', 'nombre' => 'Colegio', 'app_password' => 'abcdefghijklmnop', 'verificado_en' => now()]);
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('raw')->once()->andReturn(null);
        $mail = \Mockery::mock(SchoolMail::class)->makePartial();
        $mail->shouldReceive('mailer')->once()->andReturn($mailer);
        $this->expectException(\RuntimeException::class);
        $mail->sendRaw('recipient@example.test', 'Prueba', 'Mensaje');
    }

    public function test_enrollment_email_has_branded_html_plain_text_and_safe_links(): void
    {
        $body = "Tu PIN es A1B2C3.\n<script>alert(1)</script>\n\nSeguimiento: ".app(EnrollmentIntake::class)->baseUrl().'/ingreso';
        $data = app(EnrollmentMail::class)->data('PIN de matrícula', $body, 'solicitud:pin');
        $html = view('mail.enrollment', $data)->render();
        $plain = view('mail.enrollment-text', $data)->render();

        $this->assertStringContainsString('Ingreso estudiantil', $html);
        $this->assertStringContainsString('PIN de matrícula', $html);
        $this->assertStringContainsString('Tu PIN es A1B2C3.', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('/ingreso', $data['actionUrl']);
        $this->assertStringNotContainsString('Seguimiento:', $html);
        $this->assertStringContainsString($body, $plain);

        $credential = app(EnrollmentMail::class)->data('Acceso', 'Contraseña temporal: ejemplo', 'solicitud:credenciales');
        $this->assertStringEndsWith('/auth', $credential['actionUrl']);
    }

    public function test_four_digit_pin_uses_a_distinct_mail_card_and_private_link(): void
    {
        $link = app(EnrollmentIntake::class)->baseUrl().'/ingreso/'.$this->campaignToken.'#solicitud='.str_repeat('a', 64);
        $body = "Tu PIN es 0427. Vence en 15 minutos.\nEnlace privado: ".$link;
        $data = app(EnrollmentMail::class)->data('PIN de matrícula', $body, 'solicitud:pin:ejemplo');
        $html = view('mail.enrollment', $data)->render();
        $this->assertSame('0427', $data['pin']);
        $this->assertSame($link, $data['actionUrl']);
        $this->assertStringContainsString('Tu PIN de verificación', $html);
        $this->assertStringContainsString('>0427</div>', $html);
        $this->assertStringContainsString('Continuar inscripción', $html);
        $this->assertStringContainsString($body, view('mail.enrollment-text', $data)->render());
    }

    public function test_enrollment_email_is_sent_as_html_and_plain_text_without_smtp(): void
    {
        $mailer = Mail::build(['transport' => 'array']);
        $mailer->alwaysFrom('school@example.test', 'Colegio');
        $mail = \Mockery::mock(SchoolMail::class)->makePartial();
        $mail->shouldReceive('settings')->once()->andReturn(null);
        $mail->shouldReceive('mailer')->once()->andReturn($mailer);

        $mail->sendEnrollment('family@example.test', 'PIN de matrícula', 'Tu PIN es A1B2C3.', 'solicitud:pin');

        $messages = $mailer->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertStringContainsString('Ingreso estudiantil', $email->getHtmlBody());
        $this->assertStringContainsString('Tu PIN es A1B2C3.', $email->getTextBody());
        $this->assertSame('family@example.test', $email->getTo()[0]->getAddress());
    }

    public function test_gmail_provider_errors_do_not_expose_credentials(): void
    {
        $candidate = new CorreoConfiguracion(['email' => 'school@gmail.com', 'nombre' => 'Colegio', 'app_password' => 'abcdefghijklmnop']);
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('raw')->once()->andThrow(new \RuntimeException('AUTH abcdefghijklmnop'));
        $mail = \Mockery::mock(SchoolMail::class)->makePartial();
        $mail->shouldReceive('mailer')->once()->andReturn($mailer);
        try {
            $mail->test($candidate);
            $this->fail('An unsuccessful SMTP test must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('abcdefghijklmnop', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    private function approveMail(string $action): void
    {
        SchoolMailChangeRequest::create(['url_token' => Str::random(24),
            'tenant_id' => $this->school->id, 'requester_id' => (string) $this->rector->id, 'requester_name' => 'Rector',
            'revision' => CorreoConfiguracion::findOrFail('gmail')->revision, 'accion' => $action,
            'motivo' => 'Autorización de prueba aislada.', 'estado' => 'aprobada', 'expires_at' => now()->addDay()]);
    }
}
