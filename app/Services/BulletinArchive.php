<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\BoletinEmision;
use App\Models\Academico\BoletinPlantilla;
use App\Support\Storage\ReadableStorageName;
use App\Support\Storage\StorageService;
use App\Support\StudentRosterName;
use Illuminate\Support\Facades\Storage;

/** PDF bytes stay immutable; readable folders follow the saved template title. */
final class BulletinArchive
{
    public static function filename(array $snapshot): string
    {
        $student = $snapshot['estudiante'];
        $name = $student['nombre_lista'] ?? StudentRosterName::display($student['nombre']);
        $period = $snapshot['periodo']['nombre'] ?? 'Final';

        $parts = array_map(fn ($part) => ReadableStorageName::segment((string) $part, 'Boletin', 110),
            [$name, $period, $student['grado'], $student['grupo'], $snapshot['ano']]);
        // Leave room for the extension on filesystems with a 255-byte component limit.
        while (strlen(implode('_', $parts).'.pdf') > 240) {
            $lengths = array_map('strlen', $parts);
            $index = array_search(max($lengths), $lengths, true);
            $parts[$index] = rtrim(mb_strcut($parts[$index], 0, $lengths[$index] - 1, 'UTF-8'), '._-');
        }

        return implode('_', $parts).'.pdf';
    }

    public static function path(BoletinEmision $issue): string
    {
        $snapshot = $issue->snapshot;
        $student = $snapshot['estudiante'];
        $parts = [$snapshot['ano'], $student['grado'], $student['grupo'], $snapshot['periodo']['nombre'] ?? 'Final'];
        $folder = implode('/', array_map(fn ($part) => ReadableStorageName::segment((string) $part, 'Boletin', 60), $parts));
        $name = $issue->plantilla_id ? BoletinPlantilla::withTrashed()->whereKey($issue->plantilla_id)->value('nombre') : null;
        $revision = 'Revisión_'.$issue->revision.'_'.ReadableStorageName::segment(
            $name ?? $snapshot['plantilla']['nombre'] ?? 'Boletín', 'Boletín', 100);
        $root = StorageService::tenantFolder(tenant()).'/boletines/'.$folder.'/'.$revision;
        $filename = self::filename($snapshot);
        // Homonyms and designs with the same title remain separate, without exposing identifiers.
        for ($copy = 1; $copy <= 10000; $copy++) {
            $candidate = $root.($copy > 1 ? '_Copia_'.$copy : '').'/'.$filename;
            $other = BoletinEmision::where('pdf_path', $candidate)->whereKeyNot($issue->id)->exists();
            if (! $other && ($candidate === $issue->pdf_path || ! Storage::disk('tenant')->exists($candidate))) {
                return $candidate;
            }
        }
        abort(409, 'No se pudo reservar un nombre para el boletín.');
    }

    /** The tenant disk is local: exclusive creation prevents concurrent name collisions. */
    public static function write(BoletinEmision $issue, string $bytes): string
    {
        $disk = Storage::disk('tenant');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $path = self::path($issue);
            abort_unless($disk->makeDirectory(dirname($path)), 500, 'No se pudo crear la carpeta del boletín.');
            $absolute = $disk->path($path);
            $stream = @fopen($absolute, 'xb');
            if ($stream === false) {
                clearstatcache(true, $absolute);

                continue;
            }
            try {
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($stream, substr($bytes, $offset));
                    abort_unless($written, 500, 'No se pudo guardar el PDF privado.');
                    $offset += $written;
                }
            } catch (\Throwable $error) {
                fclose($stream);
                $disk->delete($path);
                throw $error;
            }
            fclose($stream);

            return $path;
        }
        abort(409, 'El nombre del archivo cambió durante el guardado. Vuelve a intentarlo.');
    }
}
