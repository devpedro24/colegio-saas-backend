<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StoredFile;
use App\Models\Tenant;
use App\Support\Storage\NullScanner;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pipeline UNICO de archivos por tenant (RN-AC-001..006, D-STORAGE).
 *
 * Verifica: whitelist MIME, deduplicacion por checksum, cuota por plan y el
 * registro de metadato central sin necesidad de inicializar tenancy.
 */
class StoragePipelineTest extends TestCase
{
    use RefreshDatabase;

    private function fakeTenant(string $plan = 'esencial'): Tenant
    {
        $tenant = new Tenant();
        $tenant->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Colegio de prueba',
            'slug' => 'colegio-test-'.uniqid(),
            'plan' => $plan,
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        return $tenant;
    }

    public function test_guarda_un_archivo_y_registra_metadato_y_url_firmada(): void
    {
        Storage::fake('tenant');

        $service = new StorageService(new \App\Support\Storage\NullScanner());
        $tenant = $this->fakeTenant('esencial');

        $file = UploadedFile::fake()->create('boletin.pdf', 80, 'application/pdf');
        $stored = $service->store($file, 'matriculas', 'rector@col.test', $tenant);

        $this->assertInstanceOf(StoredFile::class, $stored);
        $this->assertSame((string) $tenant->id, $stored->tenant_id);
        $this->assertSame('application/pdf', $stored->mime);
        $this->assertSame(80 * 1024, $stored->size);
        $this->assertTrue(Storage::disk('tenant')->exists($stored->path));
        $this->assertStringContainsString('signature=', $service->signedUrl($stored));
    }

    public function test_rechaza_un_mime_fuera_de_whitelist(): void
    {
        Storage::fake('tenant');

        $service = new StorageService(new \App\Support\Storage\NullScanner());
        $tenant = $this->fakeTenant();

        $file = UploadedFile::fake()->create('malware.exe', 80, 'application/x-msdownload');

        $this->expectException(StorageException::class);

        $service->store($file, 'general', null, $tenant);
    }

    public function test_deduplica_por_checksum_mismo_colegio(): void
    {
        Storage::fake('tenant');

        $service = new StorageService(new \App\Support\Storage\NullScanner());
        $tenant = $this->fakeTenant();

        $file = UploadedFile::fake()->create('documento.pdf', 120, 'application/pdf');

        $stored1 = $service->store($file, 'docs', null, $tenant);
        $stored2 = $service->store($file, 'docs', null, $tenant);

        $this->assertSame($stored1->id, $stored2->id);
        $this->assertSame(1, StoredFile::query()->count());
    }

    public function test_bloquea_el_upload_al_superar_la_cuota_del_plan(): void
    {
        Storage::fake('tenant');

        $tenant = $this->fakeTenant('esencial');
        $quota = (new StorageService(new \App\Support\Storage\NullScanner()))->quotaBytes($tenant);

        // Deja solo 10 bytes libres.
        StoredFile::create([
            'tenant_id' => (string) $tenant->id,
            'disk' => 'tenant',
            'path' => 'x/ocupado.pdf',
            'mime' => 'application/pdf',
            'size' => $quota - 10,
            'checksum' => str_repeat('a', 64),
            'original_name' => 'ocupado.pdf',
        ]);

        $service = new StorageService(new \App\Support\Storage\NullScanner());
        $file = UploadedFile::fake()->create('nuevo.pdf', 50, 'application/pdf');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('cuota');

        $service->store($file, 'general', null, $tenant);
    }
}