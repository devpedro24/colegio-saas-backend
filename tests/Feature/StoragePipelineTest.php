<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StoredFile;
use App\Models\Tenant;
use App\Tenancy\PrefixedCacheTenancyBootstrapper;
use App\Support\Storage\NullScanner;
use App\Support\Storage\RejectingScanner;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
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
        $tenant->saveQuietly();

        return $tenant;
    }

    public function test_database_cache_is_isolated_by_tenant_and_allows_pdf_and_word_uploads(): void
    {
        Storage::fake('tenant');
        config([
            'cache.default' => 'database',
            'cache.stores.database.connection' => config('database.default'),
            'cache.stores.database.lock_connection' => config('database.default'),
            'tenancy.bootstrappers' => [PrefixedCacheTenancyBootstrapper::class],
        ]);

        $firstTenant = $this->fakeTenant();
        $secondTenant = $this->fakeTenant();
        Cache::put('upload-isolation-test', 'central', 60);
        $service = new StorageService(new NullScanner);

        try {
            tenancy()->initialize($firstTenant);
            $this->assertNull(Cache::get('upload-isolation-test'));
            Cache::put('upload-isolation-test', 'first', 60);

            foreach (['pdf' => '%PDF-1.4 test', 'docx' => 'PK test'] as $extension => $contents) {
                $file = UploadedFile::fake()->createWithContent('Guía de prueba.'.$extension, $contents);
                $stored = $service->store($file, 'aula/Cuarto_período/Guía_de_prueba', null, $firstTenant);
                $this->assertTrue(Storage::disk('tenant')->exists($stored->path));
                $this->assertSame($contents, Storage::disk('tenant')->get($stored->path));
            }

            tenancy()->initialize($secondTenant);
            $this->assertNull(Cache::get('upload-isolation-test'));
            Cache::put('upload-isolation-test', 'second', 60);
            tenancy()->initialize($firstTenant);
            $this->assertSame('first', Cache::get('upload-isolation-test'));
        } finally {
            if (tenancy()->initialized) tenancy()->end();
        }

        $this->assertSame('central', Cache::get('upload-isolation-test'));
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
        $this->assertSame('boletin.pdf', basename($stored->path));
        $this->assertStringContainsString('Colegio_de_prueba_'.$tenant->id.'/matriculas/', $stored->path);
        $this->assertSame(80 * 1024, $stored->size);
        $this->assertTrue(Storage::disk('tenant')->exists($stored->path));
        // exists() also accepts directories: verify the actual file and its bytes.
        $this->assertSame([$stored->path], Storage::disk('tenant')->allFiles());
        $this->assertSame(file_get_contents($file->getRealPath()), Storage::disk('tenant')->get($stored->path));
        $url = $service->signedUrl($stored);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('/storage/'.\App\Support\Storage\StoredFilePublicToken::for($stored).'/download', $url);
        $this->assertStringContainsString('school='.$tenant->slug, $url);
        $this->assertStringNotContainsString('tenant='.$tenant->id, $url);
        $this->assertStringNotContainsString('/storage/'.$stored->id.'/download', $url);
        $this->get($url)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'attachment; filename=boletin.pdf');
        $this->get(str_replace('school='.$tenant->slug, 'school=otro-colegio', $url))->assertForbidden();
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

    public function test_rechaza_y_elimina_el_archivo_si_no_hay_escaneo_disponible(): void
    {
        Storage::fake('tenant');
        $tenant = $this->fakeTenant();
        $service = new StorageService(new RejectingScanner);

        try {
            $service->store(UploadedFile::fake()->create('documento.pdf', 1, 'application/pdf'),
                'general', null, $tenant);
            $this->fail('La carga sin escáner debió fallar.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('analizador de seguridad', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('tenant')->allFiles());
        $this->assertSame(0, StoredFile::query()->count());
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

    public function test_archivos_con_igual_nombre_y_contenido_distinto_no_se_sobrescriben(): void
    {
        Storage::fake('tenant');
        $tenant = $this->fakeTenant();
        $service = new StorageService(new NullScanner);
        $first = $service->store(UploadedFile::fake()->createWithContent('Mi archivo.pdf', '%PDF-primero'),
            'aula/materiales', null, $tenant);
        $second = $service->store(UploadedFile::fake()->createWithContent('Mi archivo.pdf', '%PDF-segundo'),
            'aula/materiales', null, $tenant);

        $this->assertSame('Mi_archivo.pdf', basename($first->path));
        $this->assertSame('Mi_archivo-2.pdf', basename($second->path));
        $this->assertSame('%PDF-primero', Storage::disk('tenant')->get($first->path));
        $this->assertSame('%PDF-segundo', Storage::disk('tenant')->get($second->path));
    }

    public function test_reubica_un_archivo_legacy_sin_vinculo_al_volver_a_adjuntarlo(): void
    {
        Storage::fake('tenant');
        $tenant = $this->fakeTenant();
        $old = $tenant->id.'/colegio-de-prueba/aula/archivos-sin-vinculo/guia.pdf';
        Storage::disk('tenant')->put($old, '%PDF-contenido');
        $stored = StoredFile::create(['tenant_id' => (string) $tenant->id, 'disk' => 'tenant',
            'path' => $old, 'mime' => 'application/pdf', 'size' => 14,
            'checksum' => hash('sha256', '%PDF-contenido'), 'original_name' => 'guia.pdf']);

        $attached = (new StorageService(new NullScanner))->store(
            UploadedFile::fake()->createWithContent('Guia Nueva.pdf', '%PDF-contenido'),
            'aula/primero/01a/materiales', null, $tenant);

        $this->assertSame($stored->id, $attached->id);
        $this->assertSame('Guia_Nueva.pdf', basename($attached->path));
        $this->assertSame('Guia Nueva.pdf', $attached->original_name);
        Storage::disk('tenant')->assertMissing($old);
        Storage::disk('tenant')->assertExists($attached->path);
    }

    public function test_el_mismo_documento_en_dos_recursos_aparece_en_ambas_carpetas(): void
    {
        Storage::fake('tenant');
        $tenant = $this->fakeTenant();
        $service = new StorageService(new NullScanner);
        $file = UploadedFile::fake()->createWithContent('Mi guía.docx', 'contenido compartido');

        $first = $service->store($file, 'aula/Recurso_uno', null, $tenant);
        $second = $service->store($file, 'aula/Recurso_dos', null, $tenant);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('Mi_guía.docx', basename($first->path));
        $this->assertSame('Mi_guía.docx', basename($second->path));
        $this->assertSame('contenido compartido', Storage::disk('tenant')->get($first->path));
        $this->assertSame('contenido compartido', Storage::disk('tenant')->get($second->path));
        $this->assertSame(2, StoredFile::where('tenant_id', $tenant->id)->count());
    }

    public function test_conserva_corchetes_acentos_y_espacios_como_guiones_bajos(): void
    {
        Storage::fake('tenant');
        $tenant = $this->fakeTenant();
        $file = UploadedFile::fake()->createWithContent('Guía de práctica.pdf', '%PDF-ejemplo');

        $stored = (new StorageService(new NullScanner))->store($file,
            'aula/Año_lectivo_2026/Grado_Primero_01A/Ciencias_Sociales/Cuarto_período/[Muestra]_Punto_de_partida',
            null, $tenant);

        $this->assertStringContainsString('/[Muestra]_Punto_de_partida/Guía_de_práctica.pdf', $stored->path);
        Storage::disk('tenant')->assertExists($stored->path);
    }

    public function test_un_archivo_duplicado_no_omite_la_nueva_politica_de_escaneo(): void
    {
        Storage::fake('tenant');
        $tenant = $this->fakeTenant();
        $upload = UploadedFile::fake()->create('documento.pdf', 1, 'application/pdf');
        (new StorageService(new NullScanner))->store($upload, 'docs', null, $tenant);

        $this->expectException(StorageException::class);
        (new StorageService(new RejectingScanner))->store($upload, 'docs', null, $tenant);
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
}
