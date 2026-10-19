<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\BoletinPlantilla;
use App\Models\Academico\DatosInstitucionales;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Academico\PromocionAcademica;
use App\Support\OpaqueUrlToken;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Resolves an individual, complete, closed academic context into a frozen document. */
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
        $this->plan->requireCustomBulletins();
        $item = OpaqueUrlToken::find('boletin-plantilla', $token, BoletinPlantilla::where('ambito', $scope));
        abort_unless($item && $item->revision_publicada, 422, 'La plantilla no tiene una revisión publicada.');
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
            abort_unless($period?->estaCerrado(), 422, 'El período debe estar cerrado antes de preparar el boletín.');
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
        abort_if($requiredObservation && (! $observation || $observation->estado !== 'aprobada'), 422,
            'La observación requerida debe estar aprobada antes de preparar el boletín.');
        abort_if($observation && $observation->estado !== 'aprobada', 422,
            'La observación existente debe estar aprobada antes de preparar el boletín.');

        $report = $this->book->report($enrollment);
        abort_if($report['advertencias'] !== [], 422, 'El informe tiene asignaturas pendientes en el currículo.');
        $subjects = collect($report['asignaturas']);
        abort_if($subjects->isEmpty(), 422, 'No hay asignaturas evaluables para este estudiante.');
        $selectResult = static fn (array $row): array => $period
            ? (collect($row['periodos'])->firstWhere('periodo_id', $period->id) ?? ['estado' => 'pendiente'])
            : $row['anual'];
        $subjectRows = $subjects->map(fn (array $row): array => [
            'nombre' => $row['nombre'], 'peso_area' => $row['peso_area'],
            'resultado' => $selectResult($row),
        ])->sortBy('nombre')->values()->all();
        $areaRows = collect($report['areas'])->map(fn (array $row): array => [
            'nombre' => $row['nombre'], 'resultado' => $selectResult($row),
        ])->sortBy('nombre')->values()->all();
        abort_if(collect([...$subjectRows, ...$areaRows])->contains(fn (array $row) =>
            ($row['resultado']['estado'] ?? null) !== 'calculado'), 422,
            'Hay resultados académicos pendientes. No se emite un boletín incompleto.');
        $indicators = collect($report['indicadores'] ?? [])->filter(fn (array $row) =>
            $scope === 'final' || $row['periodo_id'] === $period?->id)->values()->all();

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
                'logo_sha256' => $logo ? hash('sha256', $logo) : null],
            'estudiante' => ['nombre' => $enrollment->estudiante->name,
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

        return ['snapshot' => $snapshot,
            'huella' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'logo' => $logo];
    }

    public function render(array $snapshot, ?string $logo): string
    {
        $options = new Options();
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
