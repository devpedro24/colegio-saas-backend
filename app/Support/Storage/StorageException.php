<?php

declare(strict_types=1);

namespace App\Support\Storage;

use RuntimeException;

/**
 * Falla del pipeline de archivos (RN-AC-001..006).
 *
 * Extiende RuntimeException para que los controladores puedan responder 422
 * con el mensaje en espanol sin acoplar la capa de negocio.
 */
class StorageException extends RuntimeException
{
}
