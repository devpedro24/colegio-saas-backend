<?php

declare(strict_types=1);

namespace App\Support\Storage;

/**
 * Contrato del escaner antivirus del pipeline de archivos (RN-AC-005, D-STORAGE).
 *
 * Implementaciones:
 *   - NullScanner: stub aprobador (default mientras no haya ClamAV).
 *   - ClamAV (futuro): escaneo real via daemon/socket.
 *
 * El scanner recibe el archivo YA guardado en disco y decide si es seguro.
 * Si detecta amenaza lanza StorageException (el upload se aborta y el objeto
 * se elimina del storage).
 */
interface FileScanner
{
    /**
     * Escanea el contenido de un archivo en disco.
     *
     * @throws StorageException cuando se detecta una amenaza.
     */
    public function scan(string $disk, string $path): void;
}
