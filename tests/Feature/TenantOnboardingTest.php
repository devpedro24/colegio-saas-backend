<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    public function test_rector_cannot_skip_password_logo_or_institution_by_changing_url(): void
    {
        $this->seed(DatabaseSeeder::class);
        ['tenant' => $tenant, 'password' => $temporary] = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio inicio', 'slug' => 'inicio-'.uniqid(),
            'rector_email' => 'rector@inicio.test',
        ]);
        Storage::fake('tenant');
        // Estos escenarios parten de una cuenta que ya confirmó su segundo factor.
        $tenant->run(fn () => User::query()->update(['two_factor_confirmed_at' => now(),
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP')]));
        $token = $tenant->run(fn () => User::where('email', 'rector@inicio.test')->firstOrFail()
            ->createToken('web')->plainTextToken);

        $api = 'http://'.$tenant->slug.'.localhost/api';
        $this->withHeaders(['Accept' => 'application/json'])->withToken($token);

        $this->getJson($api.'/onboarding/status')->assertOk()
            ->assertJsonPath('password_required', true)
            ->assertJsonPath('institution_required', true);
        $this->getJson($api.'/anos-lectivos')->assertStatus(423);
        $this->putJson($api.'/onboarding/institution', $this->institution())->assertStatus(423);

        $this->postJson($api.'/account/password', [
            'current_password' => $temporary,
            'new_password' => 'NuevaClave123!',
            'new_password_confirmation' => 'NuevaClave123!',
        ])->assertOk();
        $this->assertNull($tenant->fresh()->rector_temporary_password);
        $this->getJson($api.'/onboarding/status')->assertJsonPath('password_required', false);
        $this->getJson($api.'/anos-lectivos')->assertStatus(423);

        $invalid = $this->institution();
        unset($invalid['nit']);
        $this->putJson($api.'/onboarding/institution', $invalid)->assertUnprocessable()->assertJsonValidationErrors('nit');
        $this->putJson($api.'/onboarding/institution', $this->institution())->assertOk();
        $this->getJson($api.'/anos-lectivos')->assertStatus(423);

        $this->post($api.'/onboarding/logo', [
            'logo' => UploadedFile::fake()->create('script.php', 2, 'text/x-php'),
        ])->assertUnprocessable()->assertJsonValidationErrors('logo');
        $this->post($api.'/onboarding/logo', [
            'logo' => UploadedFile::fake()->image('escudo.jpg', 1200, 800),
        ])->assertOk()->assertJsonPath('required', false);
        $this->assertSame([450, 300], array_slice(getimagesizefromstring(
            Storage::disk('tenant')->get($tenant->getKey().'/branding/logo.png')
        ), 0, 2));
        $this->get($api.'/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $status = $this->getJson($api.'/onboarding/status')->assertOk();
        $versionedUrl = 'http://'.$tenant->slug.'.localhost'.$status->json('logo_url');
        $image = $this->get($versionedUrl)->assertOk();
        $this->assertStringContainsString('immutable', $image->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('no-store', $image->headers->get('Cache-Control'));
        $this->withHeader('If-None-Match', $image->headers->get('ETag'))->get($versionedUrl)->assertStatus(304);
        $this->flushHeaders()->withHeaders(['Accept' => 'application/json'])->withToken($token);
        $this->getJson($api.'/me')->assertOk()->assertJsonPath('user.onboarding.required', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->post($api.'/onboarding/logo', [
            'logo' => UploadedFile::fake()->image('escudo-ancho.png', 900, 300),
            'aspect' => 'square', 'offset_x' => -1,
        ])->assertOk();
        $newLogoUrl = $this->getJson($api.'/onboarding/status')->json('logo_url');
        $this->assertNotSame($status->json('logo_url'), $newLogoUrl);
        $this->get($versionedUrl)->assertNotFound();
        $this->assertSame([300, 300], array_slice(getimagesizefromstring(
            Storage::disk('tenant')->get($tenant->getKey().'/branding/logo.png')
        ), 0, 2));
        $this->getJson($api.'/anos-lectivos')->assertOk();
    }

    public function test_active_school_rector_goes_to_logo_after_changing_temporary_password(): void
    {
        $this->seed(DatabaseSeeder::class);
        ['tenant' => $tenant, 'password' => $temporary] = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio previo', 'slug' => 'previo-'.uniqid(),
            'rector_email' => 'rector@previo.test',
        ]);
        $tenant->update(['status' => Tenant::STATUS_ACTIVE]);
        Storage::fake('tenant');
        // Estos escenarios parten de una cuenta que ya confirmó su segundo factor.
        $tenant->run(fn () => User::query()->update(['two_factor_confirmed_at' => now(),
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP')]));
        $token = $tenant->run(fn () => User::where('email', 'rector@previo.test')->firstOrFail()
            ->createToken('web')->plainTextToken);
        $api = 'http://'.$tenant->slug.'.localhost/api';
        $this->withHeaders(['Accept' => 'application/json'])->withToken($token);

        $this->postJson($api.'/account/password', [
            'current_password' => $temporary,
            'new_password' => 'NuevaClave123!',
            'new_password_confirmation' => 'NuevaClave123!',
        ])->assertOk();

        $this->getJson($api.'/onboarding/status')->assertOk()
            ->assertJsonPath('required', true)
            ->assertJsonPath('password_required', false)
            ->assertJsonPath('institution_required', true)
            ->assertJsonPath('logo_url', null);
        $this->getJson($api.'/anos-lectivos')->assertStatus(423);

        $this->post($api.'/onboarding/logo', [
            'logo' => UploadedFile::fake()->image('escudo.png', 900, 300),
        ])->assertOk()->assertJsonPath('required', true);
        $this->putJson($api.'/onboarding/institution', $this->institution())
            ->assertOk()->assertJsonPath('required', false);
        $this->getJson($api.'/anos-lectivos')->assertOk();
    }

    private function institution(): array
    {
        return [
            'nombre' => 'Colegio Inicio', 'nit' => '900123456-7',
            'resolucion_men' => '123 de 2026', 'direccion' => 'Calle 1 # 2-3',
            'telefono' => '6012345678', 'correo' => 'contacto@inicio.test',
        ];
    }
}
