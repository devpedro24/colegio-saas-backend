<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\Matricula;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EnrollmentMovementEvidence
{
    public static function rules(bool $required = false): array
    {
        return ['soportes' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:5'],
            'soportes.*' => EnrollmentEvidenceFile::rules()];
    }

    /** Key by name and bytes so a retried upload is independent of multipart ordering. */
    public static function fingerprint(array $files): array
    {
        $uploads = [];
        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            if (mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
                throw ValidationException::withMessages(['soportes' => 'Revisa el nombre del archivo.']);
            }
            $key = hash('sha256', $name."\0".hash_file('sha256', $file->getRealPath()));
            $uploads[$key] = $file;
        }
        ksort($uploads);

        return $uploads;
    }

    /** Called with the enrollment locked in the tenant transaction. */
    public static function attach(Matricula $enrollment, int $movement, int $version, array $uploads, User $actor, string $reason, bool $initial): void
    {
        if ($uploads === []) {
            return;
        }
        $existing = DB::table('matricula_movimiento_soportes')->where('movimiento_id', $movement)->pluck('huella')->all();
        $uploads = array_diff_key($uploads, array_flip($existing));
        $folder = 'matriculas/trayectoria/'.OpaqueUrlToken::for('matricula', $enrollment->id).'/movimiento-'.$version.'/'.Str::uuid();
        foreach ($uploads as $hash => $upload) {
            $file = EnrollmentEvidenceFile::store($upload, $folder, $actor, 'soportes');
            DB::table('matricula_movimiento_soportes')->insert(['movimiento_id' => $movement, 'archivo_token' => $file->public_token,
                'huella' => $hash, 'nombre' => $file->original_name, 'inicial' => $initial, 'actor_id' => $actor->id,
                'responsable' => $actor->name, 'motivo' => $reason, 'registrado_en' => now()]);
        }
        if ($uploads !== []) {
            AuditLogger::tenant($actor, 'CREATE', 'matricula_soportes', (string) $enrollment->id, null,
                ['movimiento' => $version, 'cantidad' => count($uploads)], $reason);
        }
    }
}
