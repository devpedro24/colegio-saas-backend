<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ColegioPublicSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_school_uses_slug_without_exposing_its_internal_id(): void
    {
        $this->admin();
        $tenant = $this->school();

        $this->getJson('/api/colegios')->assertOk()
            ->assertJsonFragment(['slug' => $tenant->slug])
            ->assertJsonMissing(['id' => $tenant->id]);
        $this->getJson('/api/colegios/'.$tenant->id)->assertNotFound();
        $this->getJson('/api/colegios/'.$tenant->slug)->assertOk()
            ->assertJsonPath('colegio.slug', $tenant->slug)
            ->assertJsonMissingPath('colegio.id');
    }

    public function test_platform_school_site_uses_tenant_scoped_opaque_selector(): void
    {
        $this->admin();
        $tenant = $this->school();
        [$id, $urlToken, $nombre] = $tenant->run(function (): array {
            $sede = Sede::create([
                'nombre' => 'Sede Principal',
                'direccion' => 'Calle 1',
                'estado' => Sede::ESTADO_ACTIVA,
            ]);

            return [$sede->id, $sede->hashed_id, $sede->nombre];
        });

        $this->getJson('/api/colegios/'.$tenant->slug.'/sedes')->assertOk()
            ->assertJsonPath('data.0.url_token', $urlToken)
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.tenant_id')
            ->assertJsonMissingPath('data.0.hashed_id');
        $this->putJson('/api/colegios/'.$tenant->slug.'/sedes/'.$id, [
            'nombre' => $nombre,
        ])->assertNotFound();
        $this->putJson('/api/colegios/'.$tenant->slug.'/sedes/'.$urlToken, [
            'nombre' => $nombre,
        ])->assertOk()->assertJsonPath('data.url_token', $urlToken)
            ->assertJsonMissingPath('data.id');

        $other = $this->school('otro-colegio-'.uniqid());
        $this->putJson('/api/colegios/'.$other->slug.'/sedes/'.$urlToken, [
            'nombre' => $nombre,
        ])->assertNotFound();
        $this->assertFalse(tenancy()->initialized);
    }

    private function admin(): void
    {
        $user = User::create([
            'name' => 'Admin', 'email' => 'colegio-selector@example.test',
            'password' => 'Password1!', 'role' => 'superadmin',
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->withToken($user->createToken('platform')->plainTextToken);
    }

    private function school(?string $slug = null): Tenant
    {
        $this->seed(DatabaseSeeder::class);
        $result = app(TenantProvisioner::class)->provision([
            'name' => 'Colegio Seguro',
            'slug' => $slug ?? 'colegio-seguro-'.uniqid(),
            'rector_email' => 'rector-'.uniqid().'@example.test',
        ]);

        return $result['tenant'];
    }
}
