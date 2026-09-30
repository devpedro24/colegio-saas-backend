<?php

declare(strict_types=1);

namespace App\Support\Storage;

/** Prevents uploads in production when no real malware scanner is configured. */
final class RejectingScanner implements FileScanner
{
    public function scan(string $disk, string $path): void
    {
        throw new StorageException('La carga de archivos requiere un analizador de seguridad configurado.');
    }
}
