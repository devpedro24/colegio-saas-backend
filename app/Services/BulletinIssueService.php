<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\BoletinPlantilla;
use App\Models\Academico\DatosInstitucionales;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Academico\PromocionAcademica;
use App\Services\Ingreso\CertifiedExternalGrades;
use App\Services\Ingreso\EntryAssessments;
use App\Services\Ingreso\GradeSourceDecisions;
use App\Support\AcademicDecimal;
use App\Support\OpaqueUrlToken;
use App\Support\StudentRosterName;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Resolves a complete academic context into an immutable document snapshot. */
final class BulletinIssueService
{
    public function __construct(private GradebookService $book, private AcademicPromotionService $promotions,
        private AcademicPlanAccess $plan) {}

    public function context(Matricula $enrollment, string $scope, ?string $periodToken): array
    {
        abort_unless(in_array($scope, ['periodo', 'final'], true), 422, 'Selecciona período o boletín final.');
        if ($scope === 'final') {
            abort_if($periodToken !== null, 422, 'El boletín final no recibe un período.');

            return ['final', null];
        }
        $period = $periodToken ? OpaqueUrlToken::find('periodo', $periodToken,
            Periodo::where('ano_lectivo_id', $enrollment->ano_lectivo_id)) : null;
        abort_unless($period, 422, 'Selecciona un período del año de la matrícula.');

        return ['periodo:'.$period->id, $period];
    }

    public function template(string $scope, ?string $token, ?int $revision = null): array
    {
        if ($token === null) {
            return ['id' => null, 'revision' => null, 'nombre' => 'Plantilla institucional base',
                'configuracion' => BulletinTemplateDesign::base($scope)];
        }
        $item = OpaqueUrlToken::find('boletin-plantilla', $token, BoletinPlantilla::where('ambito', $scope));
        abort_unless($item && $item->revision_publicada, 422, 'La plantilla no tiene una revisión publicada.');
        abort_unless($this->plan->customBulletins() || ($item->preset_key && app(TenantPlanAccess::class)->has('boletines_predefinidos')), 403, __('El plan permite únicamente diseños predefinidos.'));
        $revision ??= (int) $item->revision_publicada;
        $published = DB::table('boletin_plantilla_revisiones')->where('plantilla_id', $item->id)
            ->where('revision', $revision)->first();
        abort_unless($published, 422, 'La revisión publicada de la plantilla no existe.');

        return ['id' => $item->id, 'revision' => $revision, 'nombre' => $published->nombre,
            'configuracion' => json_decode($published->configuracion, true, 512, JSON_THROW_ON_ERROR)];
    }

    public function build(Matricula $enrollment, string $scope, ?Periodo $period, array $template): array
    {
        $enrollment->loadMissing(['estudiante', 'grupo.grado']);
        abort_unless($enrollment->estado === 'activa', 422, 'Solo se emite para una matrícula activa.');
        $year = AnoLectivo::findOrFail($enrollment->ano_lectivo_id);
        if ($scope === 'periodo') {
            abort_unless($period && $period->ano_lectivo_id === $year->id
                && in_array($period->estado, [Periodo::ESTADO_ABIERTO, Periodo::ESTADO_CERRADO], true),
                422, 'Solo se emiten boletines de períodos abiertos o cerrados del año de la matrícula.');
        } else {
            abort_unless($year->estaCerrado(), 422, 'El año debe estar cerrado antes de preparar el boletín final.');
            abort_unless(Periodo::where('ano_lectivo_id', $year->id)->count() === $year->num_periodos
                && ! Periodo::where('ano_lectivo_id', $year->id)->where('estado', '!=', Periodo::ESTADO_CERRADO)->exists(),
                422, 'Todos los períodos reales deben estar cerrados.');
        }
        $context = $period ? 'periodo:'.$period->id : 'final';
        $policy = DB::table('configuracion_boletines')->where('id', 1)->first();
        $requiredObservation = $scope === 'periodo' || (bool) ($policy?->requiere_observacion_final ?? false);
        $observation = DB::table('observaciones_boletin')->where('matricula_id', $enrollment->id)
            ->where('contexto', $context)->first();
        abort_if($requiredObservation && (! $observation || trim($observation->contenido) === ''), 422,
            'Guarda la observación requerida antes de generar el boletín.');

        $report = $this->book->report($enrollment);
        abort_if($report['advertencias'] !== [], 422, 'El informe tiene asignaturas pendientes en el currículo.');
        $subjects = collect($report['asignaturas'])->sortBy('nombre')->values();
        abort_if($subjects->isEmpty(), 422, 'No hay asignaturas evaluables para este estudiante.');
        $selectResult = static fn (array $row): array => $period
            ? (collect($row['periodos'])->firstWhere('periodo_id', $period->id) ?? ['estado' => 'pendiente'])
            : $row['anual'];
        $periodNames = collect($report['periodos'])->pluck('nombre', 'id');
        $subjectRows = $subjects->map(function (array $row) use ($selectResult, $period, $periodNames): array {
            $recognized = [];
            foreach ($row['periodos'] as $result) {
                $basis = $result['resultado_original'] ?? $result;
                $sourceKey = match ($basis['origen'] ?? null) {
                    'consolidacion' => 'consolidacion_token', 'traslado' => 'traslado_token', 'valoracion' => 'valoracion_token', default => 'equivalencia_token',
                };
                if (isset($basis[$sourceKey]) && (! $period || $result['periodo_id'] === $period->id)) {
                    $recognized[] = ['periodo' => $periodNames->get($result['periodo_id']), $sourceKey => $basis[$sourceKey],
                        'valor' => $basis['display_value'], 'categoria' => $basis['categoria_reconocida']];
                }
            }
            $subject = ['nombre' => $row['nombre'], 'peso_area' => $row['peso_area'], 'resultado' => $selectResult($row)];
            if ($recognized !== []) {
                $subject['periodos_reconocidos'] = $recognized;
            }
            return $subject;
        })->all();
        $areaRows = collect($report['areas'])->map(fn (array $row): array => [
            'nombre' => $row['nombre'], 'resultado' => $selectResult($row),
        ])->sortBy('nombre')->values()->all();
        abort_if(collect([...$subjectRows, ...$areaRows])->contains(fn (array $row) => ($row['resultado']['estado'] ?? null) !== 'calculado'), 422,
            'Hay resultados académicos pendientes. No se emite un boletín incompleto.');
        $indicators = collect($report['indicadores'] ?? [])->filter(fn (array $row) => $scope === 'final' || $row['periodo_id'] === $period?->id)->values()->all();

        $promotion = null;
        if ($scope === 'final') {
            $decision = PromocionAcademica::where('matricula_id', $enrollment->id)->first();
            abort_unless($decision && $decision->approved_at && $this->promotions->isCurrent($year, $enrollment, $decision), 422,
                'La decisión de promoción debe estar aprobada y vigente.');
            $promotion = ['resultado' => $decision->resultado, 'motivo' => $decision->motivo,
                'aprobada_en' => $decision->approved_at?->toIso8601String(), 'huella' => $decision->huella];
        }
        $institution = DatosInstitucionales::query()->first();
        $logoPath = TenantOnboarding::logoReadPath();
        $logo = Storage::disk('tenant')->exists($logoPath) ? Storage::disk('tenant')->get($logoPath) : null;
        $attendance = DB::table('asistencia_marcas as marks')
            ->join('asistencia_clases as classes', 'classes.id', '=', 'marks.asistencia_clase_id')
            ->where('marks.matricula_id', $enrollment->id)
            ->when($period, fn ($query) => $query->where('classes.periodo_id', $period->id))
            ->selectRaw("count(*) as registradas, sum(case when marks.estado = 'ausente' then 1 else 0 end) as ausentes, sum(case when marks.estado = 'tarde' then 1 else 0 end) as tardes, sum(case when marks.estado = 'justificada' then 1 else 0 end) as justificadas")
            ->first();
        $snapshot = [
            'colegio' => ['nombre' => $institution?->nombre ?: tenant('name'), 'nit' => $institution?->nit,
                'direccion' => $institution?->direccion, 'resolucion' => $institution?->resolucion_men,
                'telefono' => $institution?->telefono, 'correo' => $institution?->correo,
                'logo_sha256' => $logo ? hash('sha256', $logo) : null],
            'estudiante' => ['nombre' => $enrollment->estudiante->name,
                'nombre_lista' => $this->rosterName($enrollment),
                'grado' => $enrollment->grupo->grado->nombre, 'grupo' => $enrollment->grupo->nombre],
            'ano' => $year->nombre, 'contexto' => $context, 'tipo' => $scope,
            'periodo' => $period ? ['nombre' => $period->nombre, 'peso' => $period->peso] : null,
            'plantilla' => $template, 'asignaturas' => $subjectRows, 'areas' => $areaRows,
            'indicadores' => $indicators, 'observacion' => $observation?->contenido,
            'observacion_version' => $observation?->version, 'promocion' => $promotion,
            'asistencia' => ['registradas' => (int) ($attendance?->registradas ?? 0),
                'ausentes' => (int) ($attendance?->ausentes ?? 0), 'tardes' => (int) ($attendance?->tardes ?? 0),
                'justificadas' => (int) ($attendance?->justificadas ?? 0)],
        ];

        if (isset($template['configuracion']['canvas'])) {
            $snapshot['canvas_runtime'] = app(BulletinCanvasRuntime::class)->build($enrollment, $period, $report, $snapshot, $logo);
        }

        // The audit reference is archival metadata. Adding it must not invalidate an unchanged, previously issued PDF.
        $fingerprint = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $choices = DB::table(CertifiedExternalGrades::RESULTS.' as r')
            ->join(GradeSourceDecisions::TABLE.' as d', 'd.resultado_id', '=', 'r.id')
            ->where('r.matricula_id', $enrollment->id)
            ->when($period, fn ($q) => $q->where('r.periodo_id', $period->id))
            ->where('d.id', fn ($q) => $q->from(GradeSourceDecisions::TABLE.' as h')->select('h.id')
                ->whereColumn('h.resultado_id', 'r.id')->orderByDesc('h.version')->limit(1))
            ->get(['r.materia_id', 'r.periodo_id', 'r.token as resultado_token', 'd.fuente', 'd.version'])
            ->keyBy(fn ($row) => $row->materia_id.':'.$row->periodo_id);
        foreach ($subjects as $index => $subject) {
            $subjectId = $subject['materia_id'];
            if (!$period) {
                $snapshot['asignaturas'][$index]['materia_ref'] = $subjectId;
            }
            foreach ($subject['periodos'] as $grade) {
                $id = $grade['periodo_id'];
                $decision = $choices->get($subjectId.':'.$id);
                $choice = $decision ? ['resultado_token' => $decision->resultado_token,
                    'fuente' => $decision->fuente, 'version' => $decision->version] : null;
                if ($period) {
                    if ($period->id === $id && $choice) {
                        $snapshot['asignaturas'][$index]['eleccion_definitiva'] = $choice;
                    }
                    continue;
                }
                $basis = $grade['resultado_original'] ?? $grade;
                $snapshot['asignaturas'][$index]['definitivas_periodo']['periodo:'.$id] = [
                    'periodo' => $periodNames->get($id), 'valor' => $grade['categoria_reconocida'] ?? $grade['display_value'],
                    'fuente' => $this->gradeSource($basis), 'resultado_token' => $basis['equivalencia_token'] ?? $choice['resultado_token'] ?? null,
                    'eleccion' => $choice,
                ];
            }
        }
        return ['snapshot' => $snapshot, 'huella' => $fingerprint, 'logo' => $logo];
    }

    public function correctedGrades(Matricula $enrollment, array $previous, array $current): array
    {
        return [
            ...$this->periodSourceChanges($previous, $current),
            ...$this->annualSourceChanges($previous, $current),
            ...$this->changesBySource($enrollment, $previous, $current, 'equivalencia_token', CertifiedExternalGrades::RESULTS),
            ...$this->changesBySource($enrollment, $previous, $current, 'valoracion_token', EntryAssessments::TABLE),
        ];
    }

    private function periodSourceChanges(array $previous, array $current): array
    {
        if ($current['tipo'] !== 'periodo' || $previous['contexto'] !== $current['contexto']) {
            return [];
        }
        $oldSubjects = collect($previous['asignaturas']);
        $byName = $oldSubjects->groupBy('nombre');
        $byToken = $oldSubjects->keyBy(function ($subject) {
            $result = $subject['resultado']['resultado_original'] ?? $subject['resultado'];
            return $subject['eleccion_definitiva']['resultado_token'] ?? $result['equivalencia_token'] ?? '';
        });
        $nameCounts = array_count_values(array_column($current['asignaturas'], 'nombre'));
        $changes = [];
        foreach ($current['asignaturas'] as $subject) {
            $grade = $subject['resultado'];
            $choice = $subject['eleccion_definitiva'] ?? null;
            if (!$choice) {
                continue;
            }
            $name = $subject['nombre'];
            $token = $choice['resultado_token'];
            $old = $byToken->get($token);
            // Older school-grade PDFs have no certificate token. Match only an unambiguous subject in this period.
            if (!$old && $nameCounts[$name] === 1 && $byName->get($name)?->count() === 1) {
                $old = $byName[$name]->first();
            }
            if (!$old) {
                continue;
            }
            $prior = $old['resultado'];
            $priorBasis = $prior['resultado_original'] ?? $prior;
            $priorToken = $old['eleccion_definitiva']['resultado_token'] ?? $priorBasis['equivalencia_token'] ?? null;
            $priorSource = $this->gradeSource($priorBasis);
            if (($priorToken !== null && $priorToken !== $token) || !$priorSource || $priorSource === $choice['fuente']) {
                continue;
            }
            $changes[] = ['materia' => $name, 'periodo' => $current['periodo']['nombre'],
                'anterior' => $prior['categoria_reconocida'] ?? $prior['display_value'],
                'vigente' => $grade['categoria_reconocida'] ?? $grade['display_value'],
                'fuente_anterior' => $priorSource, 'fuente_vigente' => $choice['fuente'],
                'eleccion_resultado_token' => $token, 'eleccion_version' => $choice['version']];
        }
        return $changes;
    }

    private function gradeSource(array $result): ?string
    {
        return match ($result['origen'] ?? null) {
            'externa' => 'certificado',
            'colegio', 'valoracion', 'manual', null => 'colegio',
            default => null,
        };
    }

    private function annualSourceChanges(array $previous, array $current): array
    {
        if ($current['contexto'] !== 'final' || $previous['contexto'] !== 'final') {
            return [];
        }
        $old = collect($previous['asignaturas']);
        $byId = $old->keyBy('materia_ref');
        $byName = $old->groupBy('nombre');
        $names = array_count_values(array_column($current['asignaturas'], 'nombre'));
        $changes = [];
        foreach ($current['asignaturas'] as $subject) {
            $name = $subject['nombre'];
            $prior = isset($subject['materia_ref']) ? $byId->get($subject['materia_ref']) : null;
            if (!$prior && $names[$name] === 1 && $byName->get($name)?->count() === 1) {
                $candidate = $byName[$name]->first();
                $prior = isset($candidate['materia_ref']) ? null : $candidate;
            }
            if (!$prior) {
                continue;
            }
            foreach ($subject['definitivas_periodo'] ?? [] as $ref => $term) {
                $choice = $term['eleccion'];
                if (!$choice || $term['fuente'] !== $choice['fuente']) {
                    continue;
                }
                $before = isset($prior['definitivas_periodo']) ? ($prior['definitivas_periodo'][$ref] ?? null)
                    : $this->archivedAnnualTerm($prior, $ref, $term['periodo'], $choice['resultado_token']);
                if (empty($before['fuente']) || $before['fuente'] === $term['fuente']
                    || (isset($before['resultado_token']) && $before['resultado_token'] !== $choice['resultado_token'])) {
                    continue;
                }
                $changes[] = ['materia' => $name, 'periodo' => $term['periodo'],
                    'anterior' => $before['valor'], 'vigente' => $term['valor'],
                    'fuente_anterior' => $before['fuente'], 'fuente_vigente' => $term['fuente'],
                    'anual_anterior' => $prior['resultado']['display_value'], 'anual_vigente' => $subject['resultado']['display_value'],
                    'eleccion_resultado_token' => $choice['resultado_token'], 'eleccion_version' => $choice['version']];
            }
        }
        return $changes;
    }

    /** Read the period evidence frozen inside a final issued before per-period sources were archived. */
    private function archivedAnnualTerm(array $subject, string $reference, string $name, string $certificateToken): ?array
    {
        $annual = $subject['resultado']['resultado_original'] ?? $subject['resultado'];
        $input = collect($annual['trace']['inputs'] ?? [])->firstWhere('reference', $reference);
        if (!isset($input['value'])) {
            return null;
        }
        $records = collect($subject['periodos_reconocidos'] ?? []);
        $recognized = $records->firstWhere('equivalencia_token', $certificateToken) ?? $records->firstWhere('periodo', $name);
        if ($recognized && !isset($recognized['equivalencia_token']) && !isset($recognized['valoracion_token'])) {
            return null;
        }
        return ['fuente' => isset($recognized['equivalencia_token']) ? 'certificado' : 'colegio',
            'resultado_token' => $recognized['equivalencia_token'] ?? null,
            'valor' => $recognized['categoria'] ?? (string) BigRational::of($input['value'])
                ->toScale(SieeConfiguration::RESULT_DECIMALS, RoundingMode::HalfDown)];
    }

    private function changesBySource(Matricula $enrollment, array $previous, array $current, string $sourceKey, string $table): array
    {
        $final = $current['contexto'] === 'final' && $previous['contexto'] === 'final';
        $prior = [];
        foreach ($previous['asignaturas'] as $subject) {
            foreach ($subject['periodos_reconocidos'] ?? [] as $grade) {
                if (isset($grade[$sourceKey])) {
                    $prior[$grade[$sourceKey]] = [...$grade, 'anual' => $subject['resultado']['display_value'] ?? null];
                }
            }
        }
        $changes = [];
        foreach ($current['asignaturas'] as $subject) {
            foreach ($subject['periodos_reconocidos'] ?? [] as $grade) {
                $token = $grade[$sourceKey] ?? null;
                $old = $prior[$token] ?? null;
                if ($old && (AcademicDecimal::normalize($old['valor']) !== AcademicDecimal::normalize($grade['valor'])
                    || $old['categoria'] !== $grade['categoria'])) {
                    $changes[$token] = ['materia' => $subject['nombre'], 'periodo' => $grade['periodo'],
                        'anterior' => $old['categoria'] ?? $old['valor'], 'vigente' => $grade['categoria'] ?? $grade['valor']];
                    if ($final && isset($old['anual'], $subject['resultado']['display_value'])) {
                        $changes[$token]['anual_anterior'] = $old['anual'];
                        $changes[$token]['anual_vigente'] = $subject['resultado']['display_value'];
                    }
                }
            }
        }
        if (! $changes) {
            return [];
        }
        // The result and version together identify the audited correction and its private evidence.
        return DB::table($table)->where('matricula_id', $enrollment->id)
            ->whereIn('token', array_keys($changes))->where('version', '>', 0)->orderBy('id')->get(['token','version'])
            ->map(fn ($row) => [...$changes[$row->token], $sourceKey => $row->token, 'version' => $row->version])->all();
    }

    private function rosterName(Matricula $enrollment): string
    {
        $data = DB::table('ingreso_perfiles')->where('estudiante_id', $enrollment->estudiante_id)->value('datos');
        $data = $data ? json_decode($data, true, flags: JSON_THROW_ON_ERROR) : [];
        if (! empty($data['primer_apellido']) && ! empty($data['primer_nombre'])) {
            return implode(' ', array_filter(array_map(fn ($key) => trim((string) ($data[$key] ?? '')),
                ['primer_apellido', 'segundo_apellido', 'primer_nombre', 'segundo_nombre'])));
        }

        return StudentRosterName::display($enrollment->estudiante->name);
    }

    public function render(array $snapshot, ?string $logo): string
    {
        if (isset($snapshot['plantilla']['configuracion']['canvas'])) {
            return app(BulletinCanvasRenderer::class)->render($snapshot);
        }
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml(view('pdf.bulletin', ['document' => $snapshot,
            'logo' => $logo ? 'data:image/png;base64,'.base64_encode($logo) : null])->render(), 'UTF-8');
        $dompdf->render();
        $pdf = $dompdf->output();
        abort_unless(str_starts_with($pdf, '%PDF-'), 500, 'No se pudo generar el PDF.');

        return $pdf;
    }
}
