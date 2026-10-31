<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Services\BulletinObservationCache;
use App\Services\GradebookService;
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
            ];
            DB::table('configuracion_boletines')->where('id', 1)->update([...$next, 'version' => $current->version + 1, 'updated_at' => now()]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'configuracion_boletines', '1',
                ['requiere_observacion_final' => (bool) $current->requiere_observacion_final], $next);
        });

        return response()->json(['data' => $this->policy()]);
    }

    public function index(Request $request, string $matriculaToken): JsonResponse
    {
        abort_unless($request->user()->can('boletines.observaciones.gestionar')
            || $request->user()->can('boletines.observaciones.aprobar'), 403);
        $enrollment = $this->enrollment($matriculaToken, $request->user());
        $periods = Periodo::where('ano_lectivo_id', $enrollment->ano_lectivo_id)->orderBy('orden')->get();
        $observations = $this->observationRows()->where('o.matricula_id', $enrollment->id)->get()->keyBy('contexto');
        $canEdit = $request->user()->can('boletines.observaciones.gestionar')
            && AnoLectivo::findOrFail($enrollment->ano_lectivo_id)->estado === AnoLectivo::ESTADO_EN_CURSO;

        return response()->json(['data' => [
            'matricula_token' => $matriculaToken,
            'politica' => $this->policy(),
            'puede_editar' => $canEdit,
            'puede_aprobar' => $request->user()->can('boletines.observaciones.aprobar'),
            'periodos' => $periods->map(fn (Periodo $period) => [
                'periodo_token' => OpaqueUrlToken::for('periodo', $period->id),
                'nombre' => $period->nombre,
                'puede_editar' => $canEdit && $period->estado === Periodo::ESTADO_ABIERTO,
                'motivo_bloqueo' => $period->estado === Periodo::ESTADO_ABIERTO ? null
                    : 'La observación solo se puede modificar con el período abierto.',
                'observacion' => $this->present($observations->get('periodo:'.$period->id), 'periodo', $period),
            ]),
            'final' => $this->present($observations->get('final'), 'final'),
        ]]);
    }

    public function group(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('boletines.observaciones.gestionar') || $actor->can('boletines.observaciones.aprobar'), 403);
        $data = $request->validate(['grupo_token' => ['required', 'string', 'size:24'],
            'periodo_token' => ['required', 'string', 'size:24']]);
        $group = OpaqueUrlToken::find('grupo', $data['grupo_token'], Grupo::where('estado', Grupo::ESTADO_ACTIVO));
        abort_unless($group, 404);
        $period = OpaqueUrlToken::find('periodo', $data['periodo_token'], Periodo::where('ano_lectivo_id', $group->ano_lectivo_id));
        abort_unless($period, 404);
        if (in_array($actor->role, ['docente', 'director_grupo'], true)) {
            abort_unless(app(GradebookService::class)->canReadGroupReport($actor, $group->id, $group->ano_lectivo_id), 403);
        }
        // Scope, membership and period/year locks are always fresh, even on a cache hit.
        $enrollments = Matricula::where('grupo_id', $group->id)->where('ano_lectivo_id', $group->ano_lectivo_id)->orderBy('id')->get(['id']);
        $rows = app(BulletinObservationCache::class)->rows($group->id, $period->id, $enrollments->modelKeys());
        $authors = User::whereIn('id', array_unique(array_filter(array_merge(
            array_column($rows, 'updated_by'), array_column($rows, 'aprobado_por')))))->pluck('name', 'id');
        $observations = collect($rows)->keyBy('matricula_id');
        $yearOpen = AnoLectivo::findOrFail($group->ano_lectivo_id)->estado === AnoLectivo::ESTADO_EN_CURSO;

        return response()->json(['data' => [
            'grupo_token' => $data['grupo_token'], 'periodo_token' => $data['periodo_token'],
            'puede_editar' => $actor->can('boletines.observaciones.gestionar') && $yearOpen && $period->estado === Periodo::ESTADO_ABIERTO,
            'motivo_bloqueo' => ! $yearOpen ? 'El año debe estar en curso para modificar observaciones.'
                : ($period->estado === Periodo::ESTADO_ABIERTO ? null : 'La observación solo se puede modificar con el período abierto.'),
            'matriculas' => $enrollments->map(function ($enrollment) use ($observations, $authors, $period) {
                $row = $observations->get($enrollment->id);
                if ($row) {
                    $row = (object) [...$row, 'autor' => $authors->get($row['updated_by']), 'aprobador' => $authors->get($row['aprobado_por'])];
                }

                return ['matricula_token' => OpaqueUrlToken::for('matricula', $enrollment->id),
                    'observacion' => $this->present($row, 'periodo', $period)];
            }),
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
        [$enrollment, $period, $context] = $this->resolveContext($matriculaToken, $data, $request->user());
        $record = DB::transaction(function () use ($request, $enrollment, $period, $context, $data) {
            $this->assertWritableContext($enrollment, $period);
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

        app(BulletinObservationCache::class)->invalidate($enrollment->grupo_id);

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
        [$enrollment, $period, $context] = $this->resolveContext($matriculaToken, $data, $request->user());
        $record = DB::transaction(function () use ($request, $enrollment, $period, $context, $data) {
            $this->assertWritableContext($enrollment, $period);
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

        app(BulletinObservationCache::class)->invalidate($enrollment->grupo_id);

        return response()->json(['data' => $this->present($record, $data['contexto'], $period)]);
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless($request->user()->can('boletines.politica.gestionar')
            || $request->user()->can('boletines.observaciones.gestionar')
            || $request->user()->can('boletines.observaciones.aprobar'), 403);
    }

    private function policy(): array
    {
        $row = DB::table('configuracion_boletines')->where('id', 1)->first();

        return [
            'requiere_observacion_final' => (bool) ($row?->requiere_observacion_final ?? false),
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

    private function enrollment(string $token, User $actor): Matricula
    {
        $enrollment = OpaqueUrlToken::find('matricula', $token, Matricula::query());
        abort_unless($enrollment, 404);
        if (in_array($actor->role, ['docente', 'director_grupo'], true)) {
            abort_unless(app(GradebookService::class)->canReadAssignedReport($actor, $enrollment), 403);
        }

        return $enrollment;
    }

    private function resolveContext(string $token, array $data, User $actor): array
    {
        $enrollment = $this->enrollment($token, $actor);
        $year = AnoLectivo::findOrFail($enrollment->ano_lectivo_id);
        abort_if($year->estaCerrado(), 422, 'El año está cerrado. La corrección histórica requiere un proceso autorizado.');
        $period = $data['contexto'] === 'periodo'
            ? OpaqueUrlToken::find('periodo', $data['periodo_token'], Periodo::where('ano_lectivo_id', $enrollment->ano_lectivo_id))
            : null;
        abort_if($data['contexto'] === 'periodo' && ! $period, 422, 'El período no pertenece al año de la matrícula.');

        return [$enrollment, $period, $period ? 'periodo:'.$period->id : 'final'];
    }

    private function assertWritableContext(Matricula $enrollment, ?Periodo $period): void
    {
        $year = AnoLectivo::whereKey($enrollment->ano_lectivo_id)->lockForUpdate()->firstOrFail();
        abort_unless($year->estado === AnoLectivo::ESTADO_EN_CURSO, 422,
            'El año debe estar en curso para modificar observaciones.');
        if ($period) {
            $current = Periodo::whereKey($period->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->estado === Periodo::ESTADO_ABIERTO, 422,
                'La observación solo se puede modificar con el período abierto.');
        }
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
