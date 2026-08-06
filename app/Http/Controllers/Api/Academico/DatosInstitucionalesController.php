<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Events\TenantDataChanged;
use App\Models\Academico\DatosInstitucionales;
use App\Services\ConfigurationGate;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Datos institucionales del colegio (bloque 1) — BD del tenant.
 *
 * Singleton: una sola fila por colegio. `show` la devuelve (o vacia si aun no
 * existe) y `update` hace upsert sobre esa unica fila. No se versiona por ano
 * lectivo. Protegido por el permiso `academico.configurar`.
 */
class DatosInstitucionalesController extends Controller
{
    /** Devuelve la ficha institucional (singleton); null si aun no se ha creado. */
    public function show(): JsonResponse
    {
        $datos = DatosInstitucionales::query()->first();

        return response()->json(['data' => $datos]);
    }

    /** Crea o actualiza (upsert) la unica fila de datos institucionales. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'nit' => ['nullable', 'string', 'max:60'],
            'resolucion_men' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'correo' => ['nullable', 'email', 'max:255'],
            'logo_principal' => ['nullable', 'string', 'max:2048'],
            'logo_documentos' => ['nullable', 'string', 'max:2048'],
            'isotipo' => ['nullable', 'string', 'max:2048'],
            'colores' => ['nullable', 'array'],
        ]);

        $datos = DatosInstitucionales::query()->first();
        $existia = $datos !== null;
        $prev = $existia ? $datos->only(array_keys($data)) : null;

        if (! $existia) {
            $datos = new DatosInstitucionales();
        }

        $datos->fill($data)->save();

        AuditLogger::tenant(
            $request->user(),
            $existia ? 'UPDATE' : 'CREATE',
            'config.datos_institucionales',
            (string) $datos->id,
            $prev,
            $datos->only(array_keys($data)),
        );

        // Gate de operabilidad: si este bloque completa la config minima, activa.
        ConfigurationGate::maybeActivate($request->user());

        try {
            TenantDataChanged::dispatch('datos_institucionales', 'updated', $datos->nombre);
        } catch (\Throwable) {}

        return response()->json(['data' => $datos]);
    }
}
