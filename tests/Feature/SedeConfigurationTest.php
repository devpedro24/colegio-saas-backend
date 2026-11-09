<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\DatosInstitucionales;
use App\Models\Academico\EspacioFisico;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Jornada;
use App\Models\Academico\Nivel;
use App\Models\Academico\Periodo;
use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use App\Rbac\PermissionMatrix;
use App\Services\SedeAccess;
use App\Services\TenantOnboarding;
use App\Services\TenantProvisioner;
use App\Support\Auth\BrowserAuthCookies;
use App\Support\OpaqueUrlToken;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\Events\DatabaseCreated;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SedeConfigurationTest extends TestCase
{
    private Tenant $school;

    private string $base;

    private string $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $database = config('database.connections.pgsql.database');
        if (config('database.default') !== 'pgsql' || ! preg_match('/^codex_sedes_test_[a-f0-9]{12}$/D', $database)) {
            throw new \RuntimeException('Use scripts/test-sedes-postgres.php.');
        }
        $this->artisan('migrate:fresh', ['--database' => 'pgsql', '--force' => true])->assertExitCode(0);
        DatabaseConfig::generateDatabaseNamesUsing(fn ($tenant) => $database.'_t_'.substr(hash('sha256', $tenant->id), 0, 10));
        $this->seed(DatabaseSeeder::class);
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        $this->school = app(TenantProvisioner::class)->provision(['name' => 'Prueba sedes', 'slug' => 'prueba-sedes',
            'plan' => 'premium', 'rector_email' => 'rector@sedes.test'])['tenant'];
        $this->school->update(['status' => 'active', 'commercial' => ['students' => 200, 'sites' => 3, 'addons' => []]]);
        $this->base = 'http://prueba-sedes.localhost/api/estructura/sedes';
        $token = $this->school->run(function () {
            Sede::create(['nombre' => 'Principal', 'capacidad_maxima' => 100, 'estado' => 'activa']);
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['password' => 'Sedes-Test-123!', 'must_change_password' => false]);
            $coordinator = User::create(['name' => 'Coordinador', 'email' => 'coordinador@sedes.test',
                'password' => 'Sedes-Test-123!', 'role' => 'coord_combinado', 'status' => 'active']);
            $coordinator->assignRole('coord_combinado');
            $this->coordinator = OpaqueUrlToken::for('usuario', $coordinator->id);

            return $rector->createToken('test')->plainTextToken;
        });
        $this->withHeader('Authorization', 'Bearer '.$token);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        DB::setDefaultConnection(config('tenancy.database.central_connection'));
        parent::tearDown();
    }

    private function input(array $changes = []): array
    {
        return [...['nombre' => 'Norte', 'slug' => 'norte', 'capacidad_maxima' => 100,
            'estado' => 'activa', 'coordinador_token' => $this->coordinator,
            'departamento' => '41', 'municipio' => '41001', 'heredar' => false], ...$changes];
    }

    private function browserCookies($response): array
    {
        $cookies = [];
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $cookies;
    }

    private function freshBrowserRequest(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        app('auth')->forgetGuards();
    }

    private function completeInstitution(Tenant $site): void
    {
        $site->run(function () use ($site) {
            DatosInstitucionales::updateOrCreate([], ['nombre' => $site->name, 'nit' => '900123456',
                'resolucion_men' => '001', 'direccion' => 'Calle 1', 'telefono' => '3001234567',
                'correo' => 'contacto@sesiones.test']);
            Storage::disk('tenant')->put(TenantOnboarding::logoPath(), 'test-logo');
        });
    }

    public function test_browser_sessions_remain_active_for_every_school_and_campus_role(): void
    {
        $this->postJson($this->base, $this->input())->assertCreated();
        $child = Tenant::where('parent_id', $this->school->id)->firstOrFail();
        Storage::fake('tenant');
        $this->completeInstitution($this->school);
        $this->completeInstitution($child);
        $this->withMiddleware(EnsureOnboardingComplete::class)->withCredentials()->withoutHeader('Authorization');

        foreach (['school' => $this->school, 'campus' => $child] as $scope => $site) {
            $host = $site->domains()->firstOrFail()->domain;
            $base = 'http://'.$host.'/api';
            foreach (array_keys(PermissionMatrix::ROLES) as $index => $role) {
                $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)]);
                $email = $role.'@'.$scope.'.sesiones.test';
                $site->run(function () use ($role, $email) {
                    $user = User::create(['name' => 'Usuario '.$role, 'email' => $email, 'password' => 'Sedes-Test-123!',
                        'role' => $role === 'director_grupo' ? 'docente' : $role, 'status' => 'active', 'must_change_password' => false]);
                    $user->assignRole($role === 'director_grupo' ? ['docente', 'director_grupo'] : [$role]);
                });
                $this->freshBrowserRequest();
                $this->withHeader('Origin', 'http://'.$host);
                $login = $this->postJson($base.'/login', ['email' => $email, 'password' => 'Sedes-Test-123!'])
                    ->assertOk()->assertJsonPath('user.email', $email)->assertJsonPath('user.onboarding.required', false);
                $identity = $login->json('user.id');
                $cookies = $this->browserCookies($login);
                $this->withUnencryptedCookies($cookies)->withHeader('X-CSRF-Token', $cookies[BrowserAuthCookies::CSRF]);
                foreach (['/me', '/contact-options', '/account', '/onboarding/status', '/work-drafts', '/notification-records', '/notification-updates', '/me'] as $path) {
                    $this->freshBrowserRequest();
                    $response = $this->getJson($base.$path)->assertOk();
                    if ($path === '/me' || $path === '/account') {
                        $response->assertJsonPath('user.id', $identity)->assertJsonPath('user.email', $email);
                        $this->assertContains($role, $response->json('user.roles'));
                    }
                }
                $this->freshBrowserRequest();
                $this->putJson($base.'/account/profile', ['name' => 'Usuario '.$role, 'phone' => '+573001234567'])
                    ->assertOk()->assertJsonPath('user.id', $identity);
                $this->travel(30)->minutes();
                $this->freshBrowserRequest();
                $this->getJson($base.'/me')->assertOk()->assertJsonPath('user.id', $identity);
                $this->travelBack();
                $this->freshBrowserRequest();
                $this->postJson($base.'/logout')->assertOk();
                $this->freshBrowserRequest();
                $this->getJson($base.'/me')->assertUnauthorized();
            }
        }
    }

    public function test_linked_rector_and_coordinator_browser_sessions_survive_profile_reads_and_saves(): void
    {
        $this->postJson($this->base, $this->input())->assertCreated();
        $child = Tenant::where('parent_id', $this->school->id)->firstOrFail();
        Storage::fake('tenant');
        $this->completeInstitution($this->school);
        $this->completeInstitution($child);
        $this->withMiddleware(EnsureOnboardingComplete::class)->withCredentials()->withoutHeader('Authorization');
        $parentBase = 'http://prueba-sedes.localhost/api';
        $childBase = 'http://norte.prueba-sedes.localhost/api';

        foreach (['rector', 'coord_academico', 'coord_convivencia', 'coord_combinado'] as $index => $role) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 20)]);
            $email = $this->school->run(function () use ($role, $child) {
                $user = User::firstOrCreate(['role' => $role], ['name' => $role, 'email' => $role.'@sesiones.test',
                    'password' => 'Sedes-Test-123!', 'status' => 'active', 'must_change_password' => false]);
                $user->update(['must_change_password' => false]);
                $user->assignRole($role);
                if ($role !== 'rector') {
                    Sede::where('tenant_id', $child->id)->update(['coordinador_id' => $user->id]);
                }

                return $user->email;
            });
            $this->freshBrowserRequest();
            $this->withHeader('Origin', 'http://prueba-sedes.localhost');
            $parentLogin = $this->postJson($parentBase.'/login', ['email' => $email, 'password' => 'Sedes-Test-123!'])->assertOk();
            $parentCookies = $this->browserCookies($parentLogin);
            $this->freshBrowserRequest();
            $this->withHeader('Origin', 'http://norte.prueba-sedes.localhost');
            $login = $this->postJson($childBase.'/login', ['email' => $email, 'password' => 'Sedes-Test-123!'])
                ->assertOk()->assertJsonPath('user.onboarding.required', false);
            $cookies = $this->browserCookies($login);
            $identity = $login->json('user.id');
            $this->withUnencryptedCookies($cookies)->withHeader('X-CSRF-Token', $cookies[BrowserAuthCookies::CSRF]);
            foreach (['/me', '/contact-options', '/account', '/me', '/account', '/onboarding/status', '/work-drafts'] as $path) {
                $this->freshBrowserRequest();
                $response = $this->getJson($childBase.$path)->assertOk();
                if ($path === '/me' || $path === '/account') {
                    $response->assertJsonPath('user.id', $identity)->assertJsonPath('user.email', $email);
                }
            }
            $this->freshBrowserRequest();
            $saved = $this->putJson($childBase.'/account/profile', ['name' => $login->json('user.name'), 'phone' => '+573001234567'])
                ->assertOk()->assertJsonPath('user.id', $identity);
            $childCookies = $this->browserCookies($saved);
            $this->withUnencryptedCookies($childCookies)->withHeader('X-CSRF-Token', $childCookies[BrowserAuthCookies::CSRF]);
            $this->freshBrowserRequest();
            $this->getJson($childBase.'/me')->assertOk()->assertJsonPath('user.id', $identity)->assertJsonPath('user.phone', '+573001234567');

            $this->withUnencryptedCookies($parentCookies)->withHeader('Origin', 'http://prueba-sedes.localhost');
            $this->freshBrowserRequest();
            $this->getJson($parentBase.'/me')->assertOk()->assertJsonPath('user.id', $parentLogin->json('user.id'));
            $this->withUnencryptedCookies($childCookies)->withHeader('Origin', 'http://norte.prueba-sedes.localhost');
            $this->freshBrowserRequest();
            $this->getJson($childBase.'/me')->assertOk()->assertJsonPath('user.id', $identity);
        }
    }

    public function test_create_edit_capacity_geography_domains_and_records(): void
    {
        $this->getJson($this->base.'/opciones')->assertOk()->assertJsonPath('capacidad.disponibles', 100);
        $this->postJson($this->base, $this->input(['capacidad_maxima' => 101]))->assertUnprocessable();
        $this->postJson($this->base, $this->input(['capacidad_maxima' => 0]))->assertUnprocessable();
        $this->postJson($this->base, $this->input(['municipio' => '11001']))->assertUnprocessable();
        $this->postJson($this->base, $this->input(['coordinador_token' => str_repeat('x', 24)]))->assertNotFound();
        $response = $this->postJson($this->base, $this->input())->assertCreated()->assertHeader('X-Notification-Record')
            ->assertJsonPath('data.tenant_domain', 'norte.prueba-sedes.localhost')->assertJsonPath('data.coordinador_name', 'Coordinador');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|tenant_id|coordinador_id)"\s*:/', $response->getContent());
        $url = $this->base.'/'.$response->json('data.url_token');
        $this->getJson($this->base.'/opciones')->assertOk()->assertJsonPath('capacidad.disponibles', 0);
        $this->putJson($url, $this->input(['nombre' => 'Sur', 'slug' => 'sur', 'estado' => 'mantenimiento', 'capacidad_maxima' => 80]))
            ->assertOk()->assertJsonPath('data.tenant_domain', 'sur.prueba-sedes.localhost')->assertJsonPath('data.estado', 'mantenimiento');
        $this->getJson($this->base.'/opciones')->assertOk()->assertJsonPath('capacidad.disponibles', 20);
        $this->school->run(fn () => $this->assertDatabaseHas('user_notification_records', ['event' => 'structure.sede.update']));
    }

    public function test_mobile_contacts_use_country_rules_in_campus_and_account_forms(): void
    {
        $options = $this->getJson($this->base.'/opciones')->assertOk()
            ->assertJsonPath('celular_pais', 'CO')->assertJsonPath('coordinadores.0.rol', 'coord_combinado');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|coordinador_id)"\s*:/', $options->getContent());
        $catalog = $this->getJson('http://prueba-sedes.localhost/api/contact-options')->assertOk()->json('countries');
        $this->assertSame([10], collect($catalog)->firstWhere('country', 'CO')['lengths']);
        $this->assertSame('1', collect($catalog)->firstWhere('country', 'US')['calling_code']);
        foreach (['300123456', '30012345678', '573001234567', '+12015550123', 'abcdefghij'] as $invalid) {
            $this->postJson($this->base, $this->input(['telefono' => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors('telefono');
        }
        $created = $this->postJson($this->base, $this->input(['telefono' => '3001234567']))
            ->assertCreated()->assertJsonPath('data.telefono', '+573001234567');
        $this->getJson($this->base.'/'.$created->json('data.url_token'))->assertOk()
            ->assertJsonPath('data.telefono', '+573001234567');
        $this->school->update(['timezone' => 'America/New_York']);
        tenancy()->end();
        $this->getJson($this->base.'/opciones')->assertOk()->assertJsonPath('celular_pais', 'US');
        $this->putJson($this->base.'/'.$created->json('data.url_token'), $this->input(['telefono' => '2015550123']))
            ->assertOk()->assertJsonPath('data.telefono', '+12015550123');

        $profile = 'http://prueba-sedes.localhost/api/account/profile';
        $name = $this->school->run(fn () => User::where('role', 'rector')->value('name'));
        $this->school->run(fn () => User::where('role', 'rector')->update(['phone' => '300123456789']));
        $this->putJson($profile, ['name' => $name, 'phone' => '300123456789'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        foreach (['CO' => '30012345678', 'US' => '20155501234', 'XX' => '3001234567'] as $country => $invalid) {
            $this->putJson($profile, ['name' => $name, 'phone' => $invalid, 'phone_country' => $country])
                ->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $this->putJson($profile, ['name' => $name, 'phone' => '3001234567', 'phone_country' => 'CO'])
            ->assertOk()->assertJsonPath('user.phone', '+573001234567');
        $this->putJson($profile, ['name' => $name, 'phone' => '+12015550123'])
            ->assertOk()->assertJsonPath('user.phone', '+12015550123');
        $this->getJson('http://prueba-sedes.localhost/api/account')->assertOk()->assertJsonPath('user.phone', '+12015550123');
        $this->putJson($profile, ['name' => $name, 'phone' => null])->assertOk()->assertJsonPath('user.phone', null);
    }

    public function test_selective_inheritance_excludes_periods_and_retries_are_idempotent(): void
    {
        $this->school->run(function () {
            $year = AnoLectivo::create(['nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
                'fecha_fin' => '2027-12-31', 'num_periodos' => 1, 'periodo_sumatorio' => true, 'estado' => 'planeacion']);
            Periodo::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Primero', 'orden' => 1,
                'fecha_inicio' => '2027-01-01', 'fecha_fin' => '2027-12-31', 'peso' => 100, 'estado' => 'abierto']);
        });
        $this->postJson($this->base, $this->input(['heredar' => true, 'categorias' => ['grupos']]))->assertUnprocessable();
        $response = $this->postJson($this->base, $this->input(['heredar' => true, 'categorias' => ['anos']]))->assertCreated();
        $child = Tenant::where('parent_id', $this->school->id)->firstOrFail();
        $child->run(function () {
            $this->assertSame(1, AnoLectivo::count());
            $this->assertSame(0, Periodo::count());
        });
        $url = $this->base.'/'.$response->json('data.url_token');
        for ($i = 0; $i < 2; $i++) {
            $this->putJson($url, $this->input(['heredar' => true, 'categorias' => ['anos', 'periodos']]))->assertOk();
        }
        $child->run(function () {
            $this->assertSame(1, AnoLectivo::count());
            $this->assertSame(1, Periodo::count());
        });
    }

    public function test_parent_credentials_state_access_and_existing_sessions(): void
    {
        $this->postJson($this->base, $this->input())->assertCreated();
        $child = Tenant::where('parent_id', $this->school->id)->firstOrFail();
        $this->withoutHeader('Authorization');
        $this->app['auth']->forgetGuards();
        $childBase = 'http://norte.prueba-sedes.localhost/api';
        $this->postJson($childBase.'/login', ['email' => 'rector@sedes.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson($childBase.'/login', ['email' => 'rector@sedes.test', 'password' => 'Sedes-Test-123!'])->assertOk()->assertCookie('school_saas_tenant');
        $child->run(function () {
            $local = User::where('email', 'rector@sedes.test')->firstOrFail();
            $this->assertFalse(Hash::check('Sedes-Test-123!', $local->password));
        });
        $this->school->run(fn () => Sede::where('tenant_id', $child->id)->update(['estado' => 'mantenimiento']));
        $this->postJson($childBase.'/login', ['email' => 'coordinador@sedes.test', 'password' => 'Sedes-Test-123!'])->assertOk();
        $this->school->run(fn () => Sede::where('tenant_id', $child->id)->update(['estado' => 'inactiva']));
        $this->postJson($childBase.'/login', ['email' => 'coordinador@sedes.test', 'password' => 'Sedes-Test-123!'])->assertForbidden();
        $this->postJson($childBase.'/login', ['email' => 'rector@sedes.test', 'password' => 'Sedes-Test-123!'])->assertOk();
        $this->school->run(fn () => User::where('role', 'rector')->update(['password' => Hash::make('Different-Password-123!')]));
        $child->run(function () {
            $this->expectException(HttpException::class);
            app(SedeAccess::class)->authorize(User::where('role', 'rector')->firstOrFail());
        });
    }

    public function test_inherited_structure_remaps_ids_and_keeps_existing_configuration(): void
    {
        $this->school->run(function () {
            $year = AnoLectivo::create(['nombre' => '2027', 'tipo_calendario' => 'A', 'fecha_inicio' => '2027-01-01',
                'fecha_fin' => '2027-12-31', 'num_periodos' => 1, 'periodo_sumatorio' => true, 'estado' => 'planeacion']);
            $level = Nivel::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Primaria', 'nivel_educativo' => 'basica_primaria', 'estado' => 'activo']);
            $grade = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $level->id, 'nombre' => 'Primero', 'codigo' => '01', 'estado' => 'activo']);
            $shift = Jornada::create(['ano_lectivo_id' => $year->id, 'sede_id' => Sede::first()->id, 'nombre' => 'Mañana',
                'hora_inicio' => '07:00', 'hora_fin' => '12:00', 'estado' => 'activa']);
            BloqueHorario::create(['ano_lectivo_id' => $year->id, 'jornada_id' => $shift->id, 'nombre' => 'Primera hora',
                'hora_inicio' => '07:00', 'hora_fin' => '08:00', 'es_descanso' => false, 'estado' => 'activo']);
            EspacioFisico::create(['ano_lectivo_id' => $year->id, 'sede_id' => Sede::first()->id,
                'nombre' => 'Salón 1', 'tipo' => 'aula', 'capacidad' => 25, 'estado' => 'disponible']);
            Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'sede_id' => Sede::first()->id,
                'jornada_id' => $shift->id, 'nombre' => '01A', 'cupo_maximo' => 25, 'estado' => 'activo']);
        });
        $created = $this->postJson($this->base, $this->input())->assertCreated();
        $child = Tenant::where('parent_id', $this->school->id)->firstOrFail();
        $child->run(fn () => AnoLectivo::create(['nombre' => '2026', 'tipo_calendario' => 'A', 'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-12-31', 'num_periodos' => 1, 'periodo_sumatorio' => true, 'estado' => 'planeacion']));
        $input = $this->input(['heredar' => true, 'categorias' => ['anos', 'niveles', 'grados', 'jornadas', 'bloques', 'grupos', 'espacios']]);
        $url = $this->base.'/'.$created->json('data.url_token');
        $this->putJson($url, $input)->assertOk();
        $child->run(fn () => Grupo::first()->update(['cupo_maximo' => 20]));
        $this->putJson($url, $input)->assertOk();
        $child->run(function () {
            $year = AnoLectivo::where('nombre', '2027')->firstOrFail();
            $group = Grupo::firstOrFail();
            $this->assertSame(2, AnoLectivo::count());
            $this->assertSame(1, Grupo::count());
            $this->assertSame($year->id, $group->ano_lectivo_id);
            $this->assertSame($year->id, $group->grado->ano_lectivo_id);
            $this->assertSame($group->jornada_id, BloqueHorario::first()->jornada_id);
            $this->assertSame(1, EspacioFisico::count());
            $this->assertSame(20, $group->cupo_maximo);
        });
    }

    public function test_platform_uses_the_same_configuration_and_publishes_contact(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $actor = User::create(['name' => 'Plataforma', 'email' => 'platform@sedes.test',
            'password' => 'Sedes-Test-123!', 'role' => 'superadmin', 'status' => 'active']);
        $token = $actor->createToken('platform')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token);
        $this->app['auth']->forgetGuards();
        $this->putJson('http://localhost/api/platform/contact-setting', ['whatsapp' => 'javascript:bad'])->assertUnprocessable();
        $this->getJson('http://localhost/api/contact-options')->assertOk();
        $this->putJson('http://localhost/api/platform/contact-setting', ['whatsapp' => '30012345678', 'phone_country' => 'CO'])
            ->assertUnprocessable()->assertJsonValidationErrors('whatsapp');
        $this->putJson('http://localhost/api/platform/contact-setting', ['whatsapp' => '20155501234', 'phone_country' => 'US'])
            ->assertUnprocessable()->assertJsonValidationErrors('whatsapp');
        $this->putJson('http://localhost/api/platform/contact-setting', ['whatsapp' => '2015550123', 'phone_country' => 'US'])
            ->assertOk()->assertJsonPath('url', 'https://wa.me/12015550123');
        $this->getJson('http://localhost/api/platform/contact-setting')->assertOk()->assertJsonPath('whatsapp', '12015550123');
        $this->putJson('http://localhost/api/platform/contact-setting', ['whatsapp' => null])->assertOk()->assertJsonPath('url', null);
        $this->putJson('http://localhost/api/platform/contact-setting', ['whatsapp' => '573001234567'])
            ->assertOk()->assertJsonPath('url', 'https://wa.me/573001234567');
        $url = 'http://localhost/api/colegios/prueba-sedes/sedes';
        $this->getJson($url.'/opciones')->assertOk()->assertJsonPath('contacto.whatsapp', '573001234567');
        $this->postJson($url, $this->input(['capacidad_maxima' => 101]))->assertUnprocessable();
        $created = $this->postJson($url, $this->input())->assertCreated();
        $this->putJson($url.'/'.$created->json('data.url_token'), $this->input(['estado' => 'inactiva']))->assertOk();
        $principal = $this->getJson($url)->assertOk()->json('data.0.url_token');
        $this->deleteJson($url.'/'.$principal)->assertUnprocessable();
    }

    public function test_child_account_password_updates_parent_and_invalidates_old_credentials(): void
    {
        $this->postJson($this->base, $this->input())->assertCreated();
        $child = Tenant::where('parent_id', $this->school->id)->firstOrFail();
        $bearer = $child->run(function () {
            $source = app(SedeAccess::class)->source('rector@sedes.test');

            return app(SedeAccess::class)->localAccount($source)->createToken('test')->plainTextToken;
        });
        $this->withHeader('Authorization', 'Bearer '.$bearer);
        $this->app['auth']->forgetGuards();
        $this->postJson('http://norte.prueba-sedes.localhost/api/account/password', [
            'current_password' => 'Sedes-Test-123!', 'new_password' => 'New-Sedes-Password-123!',
            'new_password_confirmation' => 'New-Sedes-Password-123!',
        ])->assertOk()->assertCookie('school_saas_tenant');
        $this->school->run(fn () => $this->assertTrue(Hash::check('New-Sedes-Password-123!', User::where('role', 'rector')->firstOrFail()->password)));
        $this->withoutHeader('Authorization');
        $this->app['auth']->forgetGuards();
        $this->postJson('http://norte.prueba-sedes.localhost/api/login', ['email' => 'rector@sedes.test', 'password' => 'Sedes-Test-123!'])->assertUnprocessable();
        $this->postJson('http://norte.prueba-sedes.localhost/api/login', ['email' => 'rector@sedes.test', 'password' => 'New-Sedes-Password-123!'])->assertOk();
    }

    public function test_existing_student_sessions_are_blocked_by_site_state(): void
    {
        $this->postJson($this->base, $this->input(['capacidad_maxima' => 1]))->assertCreated();
        $child = Tenant::where('parent_id', $this->school->id)->firstOrFail();
        $token = $child->run(function () {
            $student = User::create(['name' => 'Estudiante', 'email' => 'student@sedes.test', 'password' => 'Sedes-Test-123!',
                'role' => 'estudiante', 'status' => 'active']);
            $student->assignRole('estudiante');

            return $student->createToken('test')->plainTextToken;
        });
        $this->withHeader('Authorization', 'Bearer '.$token);
        $this->app['auth']->forgetGuards();
        $this->getJson('http://norte.prueba-sedes.localhost/api/me')->assertOk();
        foreach (['mantenimiento', 'inactiva'] as $status) {
            $this->school->run(fn () => Sede::where('tenant_id', $child->id)->update(['estado' => $status]));
            $this->app['auth']->forgetGuards();
            $this->getJson('http://norte.prueba-sedes.localhost/api/me')->assertForbidden();
        }
        $child->run(function () {
            $this->expectException(HttpException::class);
            User::create(['name' => 'Sin cupo', 'email' => 'second@sedes.test', 'password' => 'Sedes-Test-123!',
                'role' => 'estudiante', 'status' => 'active']);
        });
    }

    public function test_shared_mfa_and_password_recovery_use_the_parent_account(): void
    {
        $this->postJson($this->base, $this->input())->assertCreated();
        $resetToken = $this->school->run(function () {
            $rector = User::where('role', 'rector')->firstOrFail();
            $rector->update(['two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => json_encode([hash('sha256', 'abcdef0123456789abcd')])]);

            return (new PasswordBrokerManager(app()))->broker()->createToken($rector);
        });
        $this->withoutHeader('Authorization');
        $this->app['auth']->forgetGuards();
        $url = 'http://norte.prueba-sedes.localhost/api';
        $credentials = ['email' => 'rector@sedes.test', 'password' => 'Sedes-Test-123!'];
        $this->postJson($url.'/login', $credentials)->assertUnprocessable()->assertJsonPath('mfa_required', true);
        $this->postJson($url.'/login', [...$credentials, 'code' => 'abcdef0123456789abcd'])
            ->assertOk()->assertJsonPath('user.mfa_enabled', true);
        $this->postJson($url.'/login', [...$credentials, 'code' => 'abcdef0123456789abcd'])->assertUnprocessable();
        $this->postJson($url.'/reset-password', ['email' => 'rector@sedes.test', 'token' => $resetToken,
            'password' => 'Reset-Sedes-123!', 'password_confirmation' => 'Reset-Sedes-123!'])->assertOk();
        $this->school->run(fn () => $this->assertTrue(Hash::check('Reset-Sedes-123!', User::where('role', 'rector')->firstOrFail()->password)));
    }

    public function test_renaming_the_principal_updates_hosts_and_preserves_database_contents(): void
    {
        $this->postJson($this->base, $this->input())->assertCreated();
        $principal = $this->getJson($this->base)->assertOk()->json('data.0.url_token');
        $database = $this->school->database()->getName();
        $this->putJson($this->base.'/'.$principal, $this->input(['nombre' => 'Principal renovada', 'slug' => 'renovado']))
            ->assertOk()->assertJsonPath('data.tenant_domain', 'renovado.localhost');
        $this->assertSame($database, $this->school->fresh()->database()->getName());
        $this->getJson('http://renovado.localhost/api/estructura/sedes')->assertOk()
            ->assertJsonPath('data.1.tenant_domain', 'norte.renovado.localhost');
    }

    public function test_failed_database_provisioning_releases_capacity_and_can_be_retried(): void
    {
        $fail = true;
        Event::listen(DatabaseCreated::class, function () use (&$fail) {
            if ($fail) {
                $fail = false;
                throw new \RuntimeException('Simulated provisioning failure.');
            }
        });
        $this->postJson($this->base, $this->input())->assertStatus(500);
        $this->assertSame(0, Tenant::where('parent_id', $this->school->id)->count());
        $this->getJson($this->base.'/opciones')->assertOk()->assertJsonPath('capacidad.disponibles', 100);
        $this->postJson($this->base, $this->input())->assertCreated();
    }
}
