<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AcademicYearSelection
{
    public static function fromRequest(Request $request, bool $required = true): ?AnoLectivo
    {
        $token = $request->input('ano_lectivo_token', $request->query('ano_lectivo_token'));
        if ($token !== null && $token !== '') {
            $year = OpaqueUrlToken::find('ano-lectivo', $token, AnoLectivo::query());
            if ($year === null) {
                throw ValidationException::withMessages(['ano_lectivo_token' => 'Selecciona un año lectivo válido.']);
            }
            $legacyId = $request->input('ano_lectivo_id', $request->query('ano_lectivo_id'));
            if ($legacyId !== null && $legacyId !== '' && (string) $year->id !== (string) $legacyId) {
                throw ValidationException::withMessages(['ano_lectivo_token' => 'Los selectores del año lectivo no coinciden.']);
            }

            return $year;
        }

        $id = $request->input('ano_lectivo_id', $request->query('ano_lectivo_id'));
        if ($id !== null && $id !== '') {
            if (! ctype_digit((string) $id)) {
                throw ValidationException::withMessages(['ano_lectivo_id' => 'Selecciona un año lectivo válido.']);
            }

            return AnoLectivo::findOrFail((int) $id);
        }

        $year = AnoLectivo::where('estado', AnoLectivo::ESTADO_EN_CURSO)->first()
            ?? AnoLectivo::where('estado', AnoLectivo::ESTADO_PLANIFICADO)->orderByDesc('fecha_inicio')->first();
        if ($year === null && $required) {
            throw ValidationException::withMessages(['ano_lectivo_id' => 'Crea un año lectivo antes de configurar la estructura académica.']);
        }

        return $year;
    }

    public static function editable(AnoLectivo $year): void
    {
        abort_if($year->estaCerrado(), 422, 'El año lectivo está cerrado; su configuración es de solo lectura.');
    }

    public static function assertSame(?int $actualYearId, AnoLectivo $selected): void
    {
        abort_if($actualYearId !== null && $actualYearId !== $selected->id, 422, 'El registro pertenece a otro año lectivo.');
    }
}
