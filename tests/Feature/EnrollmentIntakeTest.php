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
use App\Models\CorreoConfiguracion;
use App\Models\Ingreso\Campana;
use App\Models\Ingreso\Documento;
use App\Models\Ingreso\Notificacion;
use App\Models\Ingreso\Solicitud;
use App\Models\Plan;
use App\Models\SchoolMailChangeRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ingreso\EnrollmentIntake;
use App\Services\SchoolMail;
use App\Services\TenantOnboarding;
use App\Support\OpaqueUrlToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        Queue::fake();
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
            'configuracion' => ['grados' => [['token' => OpaqueUrlToken::for('grado', $this->grade->id), 'nombre' => 'Primero', 'cupo' => 3]],
                'campos' => [], 'privacidad' => 'Autorización de datos para pruebas de matrícula del colegio.',
                'documentos' => [['key' => 'identidad', 'nombre' => 'Identificación', 'instrucciones' => 'Legible', 'obligatorio' => true, 'formatos' => ['png'], 'max_mb' => 1, 'grados' => []]]]];
    }

    private function staff(?User $user = null): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken(($user ?? $this->rector)->createToken('test')->plainTextToken);
    }

    public function test_campaign_rejects_incomplete_fields_and_document_requirements(): void
    {
        $fields = ['nombre', 'ano_token', 'desde', 'hasta', 'configuracion.privacidad',
            'configuracion.grados.0.cupo', 'configuracion.documentos.0.nombre',
            'configuracion.documentos.0.instrucciones', 'configuracion.documentos.0.max_mb',
            'configuracion.documentos.0.formatos'];
        foreach ($fields as $field) {
            $payload = $this->configuration();
            \Illuminate\Support\Arr::forget($payload, $field);
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

    public function test_open_ended_campaign_persists_and_can_be_paused_without_losing_follow_up(): void
    {
        $payload = $this->configuration();
        $payload['hasta'] = null;
        $this->putJson($this->base.'/ingreso/campanas/'.$this->campaignToken, $payload)
            ->assertOk()->assertJsonPath('data.hasta', null);
        $this->assertNull(Campana::firstOrFail()->hasta);
        $this->visitor();
        $this->getJson($this->base.'/ingreso-publico/catalogo')->assertOk()->assertJsonPath('campanas.0.hasta', null);
        $this->start();
        $this->putJson($this->base.'/ingreso-publico/solicitud', ['datos' => $this->data(), 'enviar' => false])->assertOk();
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
        $this->putJson($this->base.'/ingreso-publico/solicitud', ['datos' => $this->data(), 'enviar' => false])->assertOk();
        $this->assertSame(1, Solicitud::count());
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
            $this->getJson($this->base.'/ingreso-publico/catalogo')->assertOk()
                ->assertJsonPath('campanas.0.nombre', 'Ingreso 2026')
                ->assertJsonPath('campanas.0.configuracion.documentos.0.nombre', 'Identificación');
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

    private function visitor(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withHeader('Origin', 'http://intake.localhost');
        if ($this->cookies) {
            $this->withUnencryptedCookies($this->cookies)->withHeader('X-CSRF-Token', $this->cookies['ingreso_csrf']);
        }
    }

    private function start(string $email = 'student@test.test'): void
    {
        $this->visitor();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/iniciar', ['email' => $email,
            'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id)])->assertAccepted();
        $notice = Notificacion::latest('id')->firstOrFail();
        preg_match('/PIN es ([A-F0-9]+)/', $notice->contenido, $m);
        $this->assertNotEmpty($m[1]);
        $this->assertStringNotContainsString($m[1], $notice->getRawOriginal('contenido'));
        $res = $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/acceder', ['email' => $email, 'pin' => $m[1]])->assertOk();
        foreach ($res->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
        $this->applicationToken = $res->json('data.url_token');
        $this->visitor();
    }

    private function data(): array
    {
        return ['primer_nombre' => 'Ana', 'primer_apellido' => 'Pruebas', 'nacimiento' => '2018-02-14',
            'tipo_documento' => 'TI', 'numero_documento' => '123456789', 'adicionales' => []];
    }

    private function upload(): string
    {
        return $this->post($this->base.'/ingreso-publico/documentos/identidad', ['archivo' => UploadedFile::fake()->image('Mi documento.png')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.documentos.0.url_token');
    }

    private function submit(): void
    {
        $this->putJson($this->base.'/ingreso-publico/solicitud', ['datos' => $this->data(), 'enviar' => true, 'consentimiento' => true])->assertOk()->assertJsonPath('data.estado', 'enviada');
    }

    private function review(string $doc, string $state, ?string $reason = null)
    {
        return $this->putJson($this->base.'/ingreso/solicitudes/'.$this->applicationToken.'/documentos/'.$doc, ['estado' => $state, 'observacion' => $reason]);
    }

    private function approve()
    {
        return $this->postJson($this->base.'/ingreso/solicitudes/'.$this->applicationToken.'/decision', ['estado' => 'aprobada', 'grado_token' => OpaqueUrlToken::for('grado', $this->grade->id)]);
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
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/acceder', ['email' => 'student@test.test', 'pin' => '123456'])->assertUnprocessable();
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

    public function test_permission_and_frozen_campaign_and_upload_validation(): void
    {
        $this->start();
        $this->post($this->base.'/ingreso-publico/documentos/identidad', ['archivo' => UploadedFile::fake()->create('script.php', 1, 'text/x-php')], ['Accept' => 'application/json'])->assertUnprocessable();
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
        $firstApplication = $this->applicationToken;
        $this->start('second@test.test');
        $secondDoc = $this->upload();
        $this->getJson($this->base.'/ingreso-publico/documentos/'.$firstDoc)->assertNotFound();
        $this->get($this->base.'/ingreso-publico/documentos/'.$secondDoc)->assertOk();
        $this->staff();
        $this->get($this->base.'/ingreso/solicitudes/'.$firstApplication.'/documentos/'.$secondDoc, ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson($this->base.'/ingreso/solicitudes/'.$firstApplication)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_expired_pin_and_recovery_do_not_create_another_request(): void
    {
        $this->start();
        preg_match('/PIN es ([A-F0-9]+)/', Notificacion::latest('id')->first()->contenido, $m);
        Solicitud::first()->update(['pin_expira' => now()->subMinute()]);
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/acceder', ['email' => 'student@test.test', 'pin' => $m[1]])->assertUnprocessable();
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/recuperar', ['email' => 'student@test.test'])->assertAccepted();
        preg_match('/PIN es ([A-F0-9]+)/', Notificacion::latest('id')->first()->contenido, $new);
        $this->assertNotSame($m[1], $new[1]);
        $this->postJson($this->base.'/ingreso-publico/'.$this->campaignToken.'/acceder', ['email' => 'student@test.test', 'pin' => $new[1]])->assertOk();
        $this->assertSame(1, Solicitud::count());
        $this->assertSame(1, User::count());
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
        $this->putJson($this->base.'/ingreso-publico/solicitud', ['datos' => ['adicionales' => []], 'enviar' => false])->assertOk();
        $this->staff();
        $this->approve()->assertUnprocessable();
        $this->assertSame(1, User::count());
        $this->visitor();
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
        $config['documentos'][] = ['key' => 'certificado', 'nombre' => 'Certificado', 'instrucciones' => '', 'obligatorio' => true, 'formatos' => ['png'], 'max_mb' => 1, 'grados' => [$otherToken]];
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
        $doc = $this->upload();
        $this->submit();
        $this->staff();
        $this->review($doc, 'aprobado')->assertOk();
        $this->approve()->assertUnprocessable();
        $this->assertSame(2, User::count());
        Plan::where('key', 'esencial')->update(['features' => []]);
        $this->getJson($this->base.'/ingreso/catalogo')->assertForbidden();
        $this->visitor();
        $this->getJson($this->base.'/ingreso-publico/catalogo')->assertForbidden();
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
        Mail::shouldReceive('raw')->once()->andReturn(null);
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
