<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Observaciones de boletín: distintas de notas, convivencia y la vista preliminar. */
final class BoletinObservacionController extends Controller
{
    public function politica(Request $request): JsonResponse
    {
        $this->authorizeRead($request);

        return response()->json(['data' => $this->policy()]);
    }

    public function guardarPolitica(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('boletines.politica.gestionar'), 403);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'requiere_observacion_final' => ['required', 'boolean'],
            'docente_edita_indicadores_grupo' => ['required', 'boolean'],
        ]);
        DB::transaction(function () use ($data, $request): void {
            DB::table('configuracion_boletines')->insertOrIgnore([
                'id' => 1, 'requiere_observacion_final' => false,
                'docente_edita_indicadores_grupo' => false, 'version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $current = DB::table('configuracion_boletines')->where('id', 1)->lockForUpdate()->first();
            abort_unless((int) $current->version === $data['version'], 409, 'La configuración cambió. Recarga antes de guardar.');
            $next = [
                'requiere_observacion_final' => $data['requiere_observacion_final'],
                'docente_edita_indicadores_grupo' => $data['docente_edita_indicadores_grupo'],
            ];
            DB::table('configuracion_boletines')->where('id', 1)->update([...$next, 'version' => $current->version + 1, 'updated_at' => now()]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'configuracion_boletines', '1',
                ['requiere_observacion_final' => (bool) $current->requiere_observacion_final,
                    'docente_edita_indicadores_grupo' => (bool) $current->docente_edita_indicadores_grupo], $next);
        });

        return response()->json(['data' => $this->policy()]);
    }

    public function index(Request $request, string $matriculaToken): JsonResponse
    {
        abort_unless($request->user()->can('boletines.observaciones.gestionar')
            || $request->user()->can('boletines.observaciones.aprobar'), 403);
        $enrollment = $this->enrollment($matriculaToken);
        $periods = Periodo::where('ano_lectivo_id', $enrollment->ano_lectivo_id)->orderBy('orden')->get();
        $observations = $this->observationRows()->where('o.matricula_id', $enrollment->id)->get()->keyBy('contexto');

        return response()->json(['data' => [
            'matricula_token' => $matriculaToken,
            'politica' => $this->policy(),
            'puede_editar' => $request->user()->can('boletines.observaciones.gestionar'),
            'puede_aprobar' => $request->user()->can('boletines.observaciones.aprobar'),
            'periodos' => $periods->map(fn (Periodo $period) => [
                'periodo_token' => OpaqueUrlToken::for('periodo', $period->id),
                'nombre' => $period->nombre,
                'observacion' => $this->present($observations->get('periodo:'.$period->id), 'periodo', $period),
            ]),
            'final' => $this->present($observations->get('final'), 'final'),
        ]]);
    }

    public function guardar(Request $request, string $matriculaToken): JsonResponse
    {
        abort_unless($request->user()->can('boletines.observaciones.gestionar'), 403);
        $data = $request->validate([
            'contexto' => ['required', Rule::in(['periodo', 'final'])],
            'periodo_token' => ['required_if:contexto,periodo', 'prohibited_if:contexto,final', 'string'],
            'contenido' => ['required', 'string', 'min:1', 'max:4000'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $data['contenido'] = trim($data['contenido']);
        abort_if($data['contenido'] === '', 422, 'Escribe la observación antes de guardarla.');
        [$enrollment, $period, $context] = $this->resolveContext($matriculaToken, $data);
        $record = DB::transaction(function () use ($request, $enrollment, $period, $context, $data) {
            $this->assertWritableYear($enrollment);
            // Serializes the first insert as well as later updates to this student's observations.
            Matricula::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $current = DB::table('observaciones_boletin')->where('matricula_id', $enrollment->id)
                ->where('contexto', $context)->lockForUpdate()->first();
            abort_unless(($current ? (int) $current->version : 0) === $data['version'], 409,
                'La observación cambió en otra sesión. Recarga antes de guardar.');
            if ($current && $current->contenido === $data['contenido']) {
                return $this->observationRows()->where('o.id', $current->id)->first();
            }
            $nextVersion = $current ? $current->version + 1 : 1;
            $values = [
                'contenido' => $data['contenido'], 'estado' => 'borrador', 'version' => $nextVersion,
                'updated_by' => $request->user()->id, 'aprobado_por' => null, 'aprobado_en' => null,
                'updated_at' => now(),
            ];
            if ($current) {
                DB::table('observaciones_boletin')->where('id', $current->id)->update($values);
                $id = $current->id;
            } else {
                $id = DB::table('observaciones_boletin')->insertGetId([
                    'matricula_id' => $enrollment->id, 'contexto' => $context,
                    'periodo_id' => $period?->id, ...$values, 'created_at' => now(),
                ]);
            }
            DB::table('observaciones_boletin_revisiones')->insert([
                'observacion_id' => $id, 'version' => $nextVersion, 'contenido' => $data['contenido'],
                'estado' => 'borrador', 'actor_id' => $request->user()->id, 'created_at' => now(),
            ]);
            AuditLogger::tenant($request->user(), $current ? 'UPDATE' : 'CREATE', 'observacion_boletin', (string) $id,
                $current ? ['version' => $current->version, 'estado' => $current->estado] : null,
                ['version' => $nextVersion, 'estado' => 'borrador']);

            return $this->observationRows()->where('o.id', $id)->first();
        });

        return response()->json(['data' => $this->present($record, $data['contexto'], $period)]);
    }

    public function aprobar(Request $request, string $matriculaToken): JsonResponse
    {
        abort_unless($request->user()->can('boletines.observaciones.aprobar'), 403);
        $data = $request->validate([
            'contexto' => ['required', Rule::in(['periodo', 'final'])],
            'periodo_token' => ['required_if:contexto,periodo', 'prohibited_if:contexto,final', 'string'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        [$enrollment, $period, $context] = $this->resolveContext($matriculaToken, $data);
        $record = DB::transaction(function () use ($request, $enrollment, $context, $data) {
            $this->assertWritableYear($enrollment);
            Matricula::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $current = DB::table('observaciones_boletin')->where('matricula_id', $enrollment->id)
                ->where('contexto', $context)->lockForUpdate()->first();
            abort_unless($current, 422, 'No hay una observación para aprobar.');
            abort_unless((int) $current->version === $data['version'], 409,
                'La observación cambió en otra sesión. Recarga antes de aprobar.');
            if ($current->estado === 'aprobada') {
                return $this->observationRows()->where('o.id', $current->id)->first();
            }
            $nextVersion = $current->version + 1;
            DB::table('observaciones_boletin')->where('id', $current->id)->update([
                'estado' => 'aprobada', 'version' => $nextVersion,
                'aprobado_por' => $request->user()->id, 'aprobado_en' => now(), 'updated_at' => now(),
            ]);
            DB::table('observaciones_boletin_revisiones')->insert([
                'observacion_id' => $current->id, 'version' => $nextVersion,
                'contenido' => $current->contenido, 'estado' => 'aprobada',
                'actor_id' => $request->user()->id, 'created_at' => now(),
            ]);
            AuditLogger::tenant($request->user(), 'APPROVE', 'observacion_boletin', (string) $current->id,
                ['version' => $current->version, 'estado' => $current->estado],
                ['version' => $nextVersion, 'estado' => 'aprobada']);

            return $this->observationRows()->where('o.id', $current->id)->first();
        });

        return response()->json(['data' => $this->present($record, $data['contexto'], $period)]);
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless($request->user()->can('boletines.politica.gestionar')
            || $request->user()->can('boletines.observaciones.gestionar')
            || $request->user()->can('boletines.observaciones.aprobar')
            || $request->user()->can('indicadores.grupo.editar'), 403);
    }

    private function policy(): array
    {
        $row = DB::table('configuracion_boletines')->where('id', 1)->first();

        return [
            'requiere_observacion_final' => (bool) ($row?->requiere_observacion_final ?? false),
            'docente_edita_indicadores_grupo' => (bool) ($row?->docente_edita_indicadores_grupo ?? false),
            'version' => (int) ($row?->version ?? 1),
        ];
    }

    private function observationRows(): Builder
    {
        return DB::table('observaciones_boletin as o')
            ->leftJoin('users as author', 'author.id', '=', 'o.updated_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'o.aprobado_por')
            ->select('o.*', 'author.name as autor', 'approver.name as aprobador');
    }

    private function enrollment(string $token): Matricula
    {
        $enrollment = OpaqueUrlToken::find('matricula', $token, Matricula::query());
        abort_unless($enrollment, 404);

        return $enrollment;
    }

    private function resolveContext(string $token, array $data): array
    {
        $enrollment = $this->enrollment($token);
        $year = AnoLectivo::findOrFail($enrollment->ano_lectivo_id);
        abort_if($year->estaCerrado(), 422, 'El año está cerrado. La corrección histórica requiere un proceso autorizado.');
        $period = $data['contexto'] === 'periodo'
            ? OpaqueUrlToken::find('periodo', $data['periodo_token'], Periodo::where('ano_lectivo_id', $enrollment->ano_lectivo_id))
            : null;
        abort_if($data['contexto'] === 'periodo' && ! $period, 422, 'El período no pertenece al año de la matrícula.');

        return [$enrollment, $period, $period ? 'periodo:'.$period->id : 'final'];
    }

    private function assertWritableYear(Matricula $enrollment): void
    {
        $year = AnoLectivo::whereKey($enrollment->ano_lectivo_id)->lockForUpdate()->firstOrFail();
        abort_if($year->estaCerrado(), 422, 'El año está cerrado. La corrección histórica requiere un proceso autorizado.');
    }

    private function present(?object $record, string $context, ?Periodo $period = null): ?array
    {
        if (! $record) {
            return null;
        }

        return [
            'contexto' => $context, 'periodo_token' => $period ? OpaqueUrlToken::for('periodo', $period->id) : null,
            'contenido' => $record->contenido, 'estado' => $record->estado,
            'version' => (int) $record->version, 'actualizado_en' => $record->updated_at,
            'aprobado_en' => $record->aprobado_en,
            'autor' => $record->autor, 'aprobador' => $record->aprobador,
        ];
    }
}
