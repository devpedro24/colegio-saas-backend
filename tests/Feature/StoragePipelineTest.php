<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Support\Storage\NullScanner;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $tenant = new Tenant;
        $tenant->forceFill([
            'id' => (string) Str::uuid(),
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

        $service = new StorageService(new NullScanner);
        $tenant = $this->fakeTenant('esencial');

        $file = UploadedFile::fake()->create('boletin.pdf', 80, 'application/pdf');
        $stored = $service->store($file, 'matriculas', 'rector@col.test', $tenant);

        $this->assertInstanceOf(StoredFile::class, $stored);
        $this->assertSame((string) $tenant->id, $stored->tenant_id);
        $this->assertSame('application/pdf', $stored->mime);
        $this->assertSame(80 * 1024, $stored->size);
        $this->assertTrue(Storage::disk('tenant')->exists($stored->path));
        $this->assertFalse(Storage::disk('tenant')->directoryExists($stored->path));
        // UploadedFile::fake conserva size declarada en metadata; lo importante
        // aqui es que el path sea un archivo real y no el directorio duplicado.
        $this->assertIsString(Storage::disk('tenant')->get($stored->path));
        $this->assertMatchesRegularExpression(
            '#^'.preg_quote((string) $tenant->id, '#').'/matriculas/[0-9a-f-]+\.pdf$#',
            $stored->path,
        );
        $this->assertStringContainsString('signature=', $service->signedUrl($stored));
    }

    public function test_rechaza_un_mime_fuera_de_whitelist(): void
    {
        Storage::fake('tenant');

        $service = new StorageService(new NullScanner);
        $tenant = $this->fakeTenant();

        $file = UploadedFile::fake()->create('malware.exe', 80, 'application/x-msdownload');

        $this->expectException(StorageException::class);

        $service->store($file, 'general', null, $tenant);
    }

    public function test_deduplica_por_checksum_mismo_colegio(): void
    {
        Storage::fake('tenant');

        $service = new StorageService(new NullScanner);
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
        $quota = (new StorageService(new NullScanner))->quotaBytes($tenant);

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

        $service = new StorageService(new NullScanner);
        $file = UploadedFile::fake()->create('nuevo.pdf', 50, 'application/pdf');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('cuota');

        $service->store($file, 'general', null, $tenant);
    }

    public function test_cuota_se_lee_del_plan_central_incluso_para_plan_personalizado(): void
    {
        Plan::create([
            'key' => 'custom-storage',
            'name' => 'Personalizado',
            'is_active' => true,
            'storage_gb' => 7,
            'features' => [],
            'sort_order' => 99,
        ]);

        $tenant = $this->fakeTenant('custom-storage');
        $this->assertSame(
            7 * 1024 * 1024 * 1024,
            (new StorageService(new NullScanner))->quotaBytes($tenant),
        );
    }

    public function test_cuota_null_en_plan_es_ilimitada(): void
    {
        Plan::create([
            'key' => 'sin-limite',
            'name' => 'Sin limite',
            'is_active' => true,
            'storage_gb' => null,
            'features' => [],
            'sort_order' => 100,
        ]);

        $this->assertNull(
            (new StorageService(new NullScanner))->quotaBytes($this->fakeTenant('sin-limite')),
        );
    }

    public function test_repara_metadata_huerfana_sin_duplicar_checksum(): void
    {
        Storage::fake('tenant');
        $tenant = $this->fakeTenant();
        $file = UploadedFile::fake()->create('documento.pdf', 32, 'application/pdf');
        $checksum = hash_file('sha256', $file->getRealPath());

        $orphan = StoredFile::create([
            'tenant_id' => (string) $tenant->id,
            'disk' => 'tenant',
            'path' => $tenant->id.'/docs/perdido.pdf',
            'mime' => 'application/pdf',
            'size' => 1,
            'checksum' => $checksum,
            'original_name' => 'perdido.pdf',
        ]);

        $stored = (new StorageService(new NullScanner))->store($file, 'docs', null, $tenant);

        $this->assertSame($orphan->id, $stored->id);
        $this->assertDatabaseCount('stored_files', 1);
        Storage::disk('tenant')->assertExists($stored->path);
    }

    public function test_no_enmascara_error_no_unique_ni_devuelve_metadata_huerfana(): void
    {
        Storage::fake('tenant');
        $tenant = $this->fakeTenant();
        $file = UploadedFile::fake()->create('documento.pdf', 32, 'application/pdf');
        $checksum = hash_file('sha256', $file->getRealPath());
        $orphan = StoredFile::create([
            'tenant_id' => (string) $tenant->id,
            'disk' => 'tenant',
            'path' => $tenant->id.'/general/ausente.pdf',
            'mime' => 'application/pdf',
            'size' => 1,
            'checksum' => $checksum,
            'original_name' => 'anterior.pdf',
        ]);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stored_files_fail_update
            BEFORE UPDATE ON stored_files
            BEGIN
                SELECT RAISE(ABORT, 'simulated write failure');
            END
            SQL);

        try {
            (new StorageService(new NullScanner))->store($file, 'general', null, $tenant);
            $this->fail('Se enmascaro una excepcion de escritura que no era una carrera UNIQUE.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('No se pudo almacenar', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS stored_files_fail_update');
        }

        $this->assertSame($tenant->id.'/general/ausente.pdf', $orphan->fresh()->path);
        $this->assertSame([], Storage::disk('tenant')->allFiles());
    }

    public function test_descarga_firmada_transmite_los_bytes_del_disco_y_nombre_original(): void
    {
        Storage::fake('tenant');
        $tenant = $this->fakeTenant();
        $contents = "contenido real del archivo\n";
        $path = $tenant->id.'/docs/boletin.pdf';
        Storage::disk('tenant')->put($path, $contents);

        $stored = StoredFile::create([
            'tenant_id' => (string) $tenant->id,
            'disk' => 'tenant',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'original_name' => 'boletin-final.pdf',
        ]);

        $response = $this->get((new StorageService(new NullScanner))->signedUrl($stored));

        $response->assertOk()->assertDownload('boletin-final.pdf');
        $this->assertSame($contents, $response->streamedContent());
    }

    public function test_eliminacion_es_logica_y_rechaza_un_colegio_distinto(): void
    {
        Storage::fake('tenant');
        $tenant = $this->fakeTenant();
        $otherTenant = $this->fakeTenant();
        $contents = 'bytes recuperables';
        $path = $tenant->id.'/docs/archivo.pdf';
        Storage::disk('tenant')->put($path, $contents);

        $stored = StoredFile::create([
            'tenant_id' => (string) $tenant->id,
            'disk' => 'tenant',
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'original_name' => 'archivo.pdf',
        ]);
        $service = new StorageService(new NullScanner);

        try {
            $service->delete($stored, $otherTenant);
            $this->fail('Se permitio borrar un archivo de otro colegio.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('no pertenece', $exception->getMessage());
        }

        $service->delete($stored, $tenant);
        $this->assertSoftDeleted('stored_files', ['id' => $stored->id]);
        Storage::disk('tenant')->assertExists($path);
    }
}
