<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Storage\ClamAvScanner;
use App\Support\Storage\StorageException;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClamAvScannerTest extends TestCase
{
    public function test_falla_cerrado_si_clamav_no_esta_disponible(): void
    {
        Storage::fake('tenant');
        Storage::disk('tenant')->put('tenant/documento.txt', 'contenido');
        config()->set('storage.clamav.endpoint', 'tcp://127.0.0.1:1');
        config()->set('storage.clamav.connect_timeout_seconds', 0.1);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('antivirus no esta disponible');

        (new ClamAvScanner)->scan('tenant', 'tenant/documento.txt');
    }

    public function test_rechaza_protocolos_de_endpoint_no_permitidos(): void
    {
        Storage::fake('tenant');
        Storage::disk('tenant')->put('tenant/documento.txt', 'contenido');
        config()->set('storage.clamav.endpoint', 'file:///tmp/falso');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('configuracion del antivirus');

        (new ClamAvScanner)->scan('tenant', 'tenant/documento.txt');
    }
}
