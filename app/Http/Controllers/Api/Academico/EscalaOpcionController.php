<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\EscalaOpcion;
use App\Models\Academico\EscalaValorativa;
use App\Services\LogoProcessor;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Categorías ordenadas de una escala visual; nunca se interpreta una nota como un ID. */
final class EscalaOpcionController extends Controller
{
    public static function present(EscalaOpcion $option): array
    {
        $scaleToken = OpaqueUrlToken::for('escala-valorativa', $option->escala_id);
        $optionToken = OpaqueUrlToken::for('escala-opcion', $option->id);

        return ['url_token' => $optionToken, 'nombre' => $option->nombre, 'orden' => $option->orden,
            'valor_equivalente' => $option->valor_equivalente,
            'emoji' => $option->emoji, 'aprueba' => $option->aprueba,
            'imagen_url' => $option->imagen_path
                ? "/api/config/escalas/{$scaleToken}/opciones/{$optionToken}/imagen?opaque=1&v="
                    .substr(hash('sha256', $option->imagen_path), 0, 12) : null];
    }

    public function save(Request $request, int $escala): JsonResponse
    {
        abort_unless($request->user()->can('academico.configurar'), 403);
        $rows = $request->validate([
            'opciones' => ['required', 'array', 'min:2', 'max:8'],
            'opciones.*.url_token' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/', 'distinct'],
            'opciones.*.nombre' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
            'opciones.*.valor_equivalente' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'opciones.*.emoji' => ['nullable', 'string', 'max:16'],
            'opciones.*.aprueba' => ['required', 'boolean'],
        ])['opciones'];
        $scale = EscalaValorativa::findOrFail($escala);
        abort_unless($scale->tipo === EscalaValorativa::TIPO_IMAGENES, 422, 'La escala debe ser por imágenes.');
        abort_if($scale->anoLectivo()->firstOrFail()->estaCerrado(), 422, 'El año lectivo está cerrado.');
        $numeric = app(\App\Services\SieeConfiguration::class)->resolve($scale->anoLectivo()->firstOrFail());
        $previousValue = null;
        foreach ($rows as $row) {
            $value = BigDecimal::of((string) $row['valor_equivalente']);
            if ($value->isLessThan((string) $numeric['valor_min']) || $value->isGreaterThan((string) $numeric['valor_max'])
                || ($previousValue !== null && ! $previousValue->isGreaterThan($value))) {
                throw ValidationException::withMessages(['opciones' => 'Los valores de las categorías deben estar dentro de la escala institucional y disminuir estrictamente de arriba hacia abajo.']);
            }
            $previousValue = $value;
        }

        DB::transaction(function () use ($rows, $scale, $request) {
            $existing = EscalaOpcion::where('escala_id', $scale->id)->lockForUpdate()->get();
            $inUse = DB::table('calificaciones')->whereIn('escala_opcion_id', $existing->modelKeys())->exists();
            $keep = [];
            foreach ($rows as $index => $row) {
                $option = isset($row['url_token'])
                    ? OpaqueUrlToken::find('escala-opcion', $row['url_token'], EscalaOpcion::where('escala_id', $scale->id))
                    : new EscalaOpcion(['escala_id' => $scale->id]);
                abort_unless($option, 404);
                if ($inUse && (! $option->exists || $option->orden !== $index + 1 || $option->aprueba !== $row['aprueba']
                    || ! BigDecimal::of((string) $option->valor_equivalente)->isEqualTo((string) $row['valor_equivalente']))) {
                    throw ValidationException::withMessages(['opciones' => 'La escala ya tiene valoraciones. Solo puedes cambiar nombres e imágenes; crea una escala nueva para modificar cantidad, orden, equivalencias o aprobación.']);
                }
                $before = $option->exists ? $option->toArray() : null;
                $option->fill(['nombre' => trim($row['nombre']), 'orden' => $index + 1,
                    'valor_equivalente' => $row['valor_equivalente'],
                    'emoji' => $row['emoji'] ?? null, 'aprueba' => $row['aprueba']])->save();
                $keep[] = $option->id;
                AuditLogger::tenant($request->user(), $before ? 'UPDATE' : 'CREATE', 'escala_opcion',
                    (string) $option->id, $before, $option->toArray());
            }
            foreach ($existing->whereNotIn('id', $keep) as $option) {
                if ($inUse) {
                    throw ValidationException::withMessages(['opciones' => 'No puedes quitar categorías de una escala con valoraciones registradas.']);
                }
                $before = $option->toArray();
                $option->delete();
                AuditLogger::tenant($request->user(), 'DELETE', 'escala_opcion', (string) $option->id, $before);
            }
        });

        return response()->json(['data' => $scale->opciones()->get()->map(self::present(...))]);
    }

    public function upload(Request $request, int $escala, int $opcion): JsonResponse
    {
        abort_unless($request->user()->can('academico.configurar'), 403);
        $option = EscalaOpcion::where('escala_id', $escala)->findOrFail($opcion);
        abort_unless($option->escala->tipo === EscalaValorativa::TIPO_IMAGENES, 422);
        abort_if($option->escala->anoLectivo()->firstOrFail()->estaCerrado(), 422, 'El año lectivo está cerrado.');
        $request->validate(['imagen' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:2048']]);
        $bytes = file_get_contents($request->file('imagen')->getRealPath());
        $info = getimagesizefromstring($bytes ?: '');
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
            || min($info[0], $info[1]) < 64 || max($info[0], $info[1]) > 3000) {
            throw ValidationException::withMessages(['imagen' => 'Sube PNG o JPG de al menos 64 × 64 y máximo 3000 × 3000 píxeles.']);
        }
        try {
            $processed = LogoProcessor::process($bytes, 'square');
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['imagen' => 'No se pudo procesar la imagen. Sube un PNG o JPG válido.']);
        }
        $hash = substr(hash('sha256', $processed), 0, 16);
        $path = "escalas/{$escala}/opciones/{$opcion}-{$hash}.png";
        Storage::disk('tenant')->put($path, $processed);
        $before = $option->toArray();
        $option->update(['imagen_path' => $path]);
        AuditLogger::tenant($request->user(), 'UPDATE', 'escala_opcion_imagen', (string) $option->id,
            $before, $option->toArray());

        return response()->json(['data' => self::present($option)]);
    }

    public function image(Request $request, int $escala, int $opcion): BinaryFileResponse
    {
        $option = EscalaOpcion::where('escala_id', $escala)->findOrFail($opcion);
        abort_unless($option->imagen_path && Storage::disk('tenant')->exists($option->imagen_path), 404);
        $etag = substr(hash_file('sha256', Storage::disk('tenant')->path($option->imagen_path)), 0, 16);
        $response = response()->file(Storage::disk('tenant')->path($option->imagen_path), [
            'Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag($etag);
        $response->isNotModified($request);

        return $response;
    }
}
