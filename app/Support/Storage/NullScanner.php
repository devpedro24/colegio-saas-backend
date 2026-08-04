<?php

declare(strict_types=1);

namespace App\Support\Storage;

/**
 * Stub aprobador del pipeline de antivirus (D-STORAGE).
 *
 * Aprueba TODO archivo. Es el valor por defecto mientras no se conecte un
 * motor real (ClamAV). El contrato de la interfaz queda fijado; el dia que se
 * enchufe ClamAV basta con cambiar el binding en un service provider.
 */
final class NullScanner implements FileScanner
{
    public function scan(string $disk, string $path): void
    {
        // Aprobador: no detecta amenazas.
    }
}
