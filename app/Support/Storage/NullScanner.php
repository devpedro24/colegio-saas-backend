<?php

declare(strict_types=1);

namespace App\Support\Storage;

/**
 * Stub aprobador del pipeline de antivirus (D-STORAGE).
 *
 * Aprueba TODO archivo sin detectar amenazas. Solo se selecciona mediante
 * STORAGE_SCANNER=null en los entornos local/testing. Los otros entornos
 * requieren un motor real (ClamAV); no usan este aprobador por defecto.
 */
final class NullScanner implements FileScanner
{
    public function scan(string $disk, string $path): void
    {
        // Aprobador: no detecta amenazas.
    }
}
