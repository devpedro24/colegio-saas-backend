<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Ingreso\Campana;
use App\Models\Ingreso\Documento;
use App\Models\Ingreso\Solicitud;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\GradebookService;
use App\Services\Ingreso\CertifiedExternalGrades;
use App\Services\Ingreso\ClosedGradeAuthorizations;
use App\Services\Ingreso\GradeSourceDecisions;
use App\Services\Ingreso\PendingCertificates;
use App\Services\Ingreso\EntryAssessments;
use App\Services\Ingreso\EarlyPromotions;
use App\Services\Ingreso\EarlyPromotionCertificates;
use App\Services\Ingreso\EnrollmentEvidenceFile;
use App\Services\Ingreso\EnrollmentIntake;
use App\Services\Ingreso\EnrollmentMovementEvidence;
use App\Services\Ingreso\EnrollmentSubmission;
use App\Services\Ingreso\EnrollmentTrajectory;
use App\Services\Ingreso\SplitPeriodGrades;
use App\Services\SchoolMail;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\StoredFilePublicToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Manages enrollment and provides read-only access to authorized academic history. */
final class EnrollmentRosterController extends Controller
{
    public function __construct(private EnrollmentIntake $intake) {}

    private function permit(Request $request): void
    {
        $this->intake->requirePlan();
        abort_unless($request->user()->can('academico.matriculas.gestionar'), 403);
    }

    public function index(Request $request)
    {
        $this->permit($request);
        $data = $request->validate(['ano_token' => 'nullable|string|size:24', 'grupo_token' => 'nullable|string|size:24',
            'campana_token' => 'nullable|string|size:24', 'estudiante_token' => 'nullable|string|size:24', 'buscar' => 'nullable|string|max:120',
            'origen' => ['nullable', Rule::in(['manual', 'enlace'])], 'page' => 'sometimes|integer|min:1']);
        $student = ! empty($data['estudiante_token']) ? $this->intake->resolve('usuario', $data['estudiante_token'], User::where('role', 'estudiante')) : null;
        $years = AnoLectivo::when($student, fn ($q) => $q->whereIn('id', Matricula::where('estudiante_id', $student->id)->select('ano_lectivo_id')))
            ->orderByDesc('nombre')->get();
        $year = ! empty($data['ano_token']) ? $this->intake->resolve('ano-lectivo', $data['ano_token'], AnoLectivo::query())
            : ($years->firstWhere('estado', 'en_curso') ?? $years->first());
        $groups = Grupo::with('grado', 'sede')->where('ano_lectivo_id', $year?->id)->orderBy('nombre')->get();
        $campaigns = Campana::where('ano_lectivo_id', $year?->id)->orderByDesc('id')->get();
        $query = Matricula::with('estudiante', 'grupo.grado', 'grupo.sede')->where('ano_lectivo_id', $year?->id);
        if ($student) {
            $query->where('estudiante_id', $student->id);
        }
        if (! empty($data['grupo_token'])) {
            $group = $this->intake->resolve('grupo', $data['grupo_token'], Grupo::where('ano_lectivo_id', $year?->id));
            $query->where('grupo_id', $group->id);
        }
        if (! empty($data['campana_token'])) {
            $campaign = $this->intake->resolve('ingreso-campana', $data['campana_token'], Campana::where('ano_lectivo_id', $year?->id));
            $query->whereIn('id', Solicitud::where('campana_id', $campaign->id)->whereNotNull('matricula_id')->select('matricula_id'));
        }
        if (! empty($data['origen'])) {
            $linked = Solicitud::whereNotNull('matricula_id')->whereNotIn('id', DB::table('ingreso_historial')
                ->where('evento', 'registro_manual')->select('solicitud_id'))->select('matricula_id');
            $data['origen'] === 'enlace' ? $query->whereIn('id', $linked) : $query->whereNotIn('id', $linked);
        }
        if (! empty($data['buscar'])) {
            $query->whereHas('estudiante', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($data['buscar']).'%']));
        }
        $page = $query->orderBy('id')->paginate(30);

        return response()->json(['data' => $page->getCollection()->map($this->present(...)),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            'ano_token' => $year ? OpaqueUrlToken::for('ano-lectivo', $year->id) : null,
            'anos' => $years->map(fn ($y) => ['token' => OpaqueUrlToken::for('ano-lectivo', $y->id), 'nombre' => $y->nombre, 'estado' => $y->estado]),
            'grupos' => $groups->map(fn ($g) => ['token' => OpaqueUrlToken::for('grupo', $g->id), 'grado_token' => OpaqueUrlToken::for('grado', $g->grado_id),
                'nombre' => $g->grado->nombre.' / '.$g->nombre, 'sede' => $g->sede?->nombre ?? tenant('name'), 'estado' => $g->estado]),
            'campanas' => $campaigns->map(fn ($campaign) => $this->intake->campaign($campaign)), 'correo_operativo' => app(SchoolMail::class)->ready()]);
    }

    private function present(Matricula $enrollment): array
    {
        $application = Solicitud::with('campana')->where('matricula_id', $enrollment->id)->first();
        $manual = ! $application || DB::table('ingreso_historial')->where('solicitud_id', $application->id)->where('evento', 'registro_manual')->exists();

        return ['token' => OpaqueUrlToken::for('matricula', $enrollment->id), 'nombre' => $enrollment->estudiante->name,
            'email' => $enrollment->estudiante->email, 'estado' => $enrollment->estado,
            'grupo' => $enrollment->grupo?->grado?->nombre.' / '.$enrollment->grupo?->nombre,
            'sede' => $enrollment->grupo?->sede?->nombre ?? tenant('name'),
            'convocatoria' => $application?->campana?->nombre, 'origen' => $manual ? 'manual' : 'enlace'];
    }

    public function show(Request $request, string $token, EnrollmentTrajectory $trajectory)
    {
        $this->permit($request);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::with('estudiante', 'grupo.grado', 'grupo.sede'));
        $application = Solicitud::where('matricula_id', $enrollment->id)->first();
        $profile = DB::table('ingreso_perfiles')->where('estudiante_id', $enrollment->estudiante_id)->first();
        AuditLogger::tenant($request->user(), 'READ', 'matricula', (string) $enrollment->id);

        return response()->json(['data' => [...$this->present($enrollment),
            'trayectoria' => [...$trajectory->details($enrollment, $request->user()),
                'promocion_anticipada' => app(EarlyPromotions::class)->details($enrollment, $request->user())],
            'ver_consolidaciones' => $request->user()->canAny([EntryAssessments::REGISTER, EntryAssessments::APPROVE]) && SplitPeriodGrades::active([$enrollment->id])->exists(),
            'ver_valoraciones_ingreso' => $request->user()->canAny([EntryAssessments::REGISTER, EntryAssessments::APPROVE]),
            'ver_definitivas_externas' => CertifiedExternalGrades::visible($request->user()),
            'ver_autorizaciones_cierre' => ClosedGradeAuthorizations::visible($request->user()),
            'datos' => $application?->datos ?? ($profile ? json_decode($profile->datos, true) : []),
            'documentos' => $application ? $this->intake->present($application)['documentos'] : []]]);
    }

    public function recommendPromotion(Request $request, string $token, EarlyPromotions $promotions)
    {
        $this->permit($request);
        abort_unless($request->user()->can(EarlyPromotions::RECOMMEND), 403);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        $data = $request->validate(['grupo_token' => 'required|string|size:24', 'version' => 'required|integer|min:0',
            'fecha_recomendacion' => 'required|date_format:Y-m-d', 'recomendacion' => 'required|string|max:2000',
            'fecha_consentimiento' => 'required|date_format:Y-m-d|before_or_equal:fecha_recomendacion',
            'consentimiento' => EnrollmentEvidenceFile::rules()]);
        $promotions->recommend($enrollment, $request->user(), $data);
        return response()->noContent();
    }

    public function decidePromotion(Request $request, string $token, string $case, EarlyPromotions $promotions)
    {
        $this->permit($request);
        abort_unless($request->user()->can(EarlyPromotions::DECIDE), 403);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        $data = $request->validate(['decision' => 'required|in:aprobada,no_aprobada', 'acta_numero' => 'required|string|max:100',
            'fecha_decision' => 'required|date_format:Y-m-d', 'acta' => EnrollmentEvidenceFile::rules()]);
        $promotions->decide($enrollment, $case, $request->user(), $data);
        return response()->noContent();
    }

    public function issuePromotionCertificate(Request $request, string $token, string $case, EarlyPromotionCertificates $certificates)
    {
        $this->permit($request);
        abort_unless($request->user()->can(EarlyPromotionCertificates::ISSUE), 403);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        $certificates->issue($enrollment, $case, $request->user());

        return response()->noContent();
    }

    public function downloadPromotionCertificate(Request $request, string $token, string $case, string $format, EarlyPromotionCertificates $certificates)
    {
        $this->permit($request);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        return $certificates->download($enrollment, $case, $request->user(), $format === 'pdf-verificable');
    }

    public function promotionEvidence(Request $request, string $token, string $case, string $kind)
    {
        $this->permit($request);
        abort_unless($request->user()->canAny([EarlyPromotions::RECOMMEND, EarlyPromotions::DECIDE]), 403);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        abort_unless(in_array($kind, ['consentimiento', 'acta'], true), 404);
        $document = DB::table(EarlyPromotions::TABLE)->where('matricula_id', $enrollment->id)->where('token', $case)->value($kind.'_token');
        abort_unless($document, 404);
        return $this->downloadGradeEvidence($request, $enrollment, $document, 'promocion_anticipada');
    }

    public function correctPlacement(Request $request, string $token, EnrollmentTrajectory $trajectory)
    {
        $this->permit($request);
        $permission = $request->filled('rectifica_version') ? EnrollmentTrajectory::RECTIFY : EnrollmentTrajectory::PERMISSION;
        abort_unless($request->user()->can($permission), 403);
        $data = $request->validate(['grupo_token' => 'required|string|size:24', 'fecha_efectiva' => 'required|date_format:Y-m-d',
            'motivo' => 'required|string|max:1000', 'version' => 'required|integer|min:0',
            'rectifica_version' => 'sometimes|required|integer|min:1', ...EnrollmentMovementEvidence::rules()]);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        $trajectory->correct($enrollment, $request->user(), $data);

        return response()->noContent();
    }

    public function addEvidence(Request $request, string $token, EnrollmentTrajectory $trajectory)
    {
        $this->permit($request);
        abort_unless($request->user()->canAny([EnrollmentTrajectory::PERMISSION, EnrollmentTrajectory::RECTIFY]), 403);
        $data = $request->validate(['movimiento_version' => 'required|integer|min:1', 'motivo' => 'required|string|max:1000',
            ...EnrollmentMovementEvidence::rules(required: true)]);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        $trajectory->addEvidence($enrollment, $request->user(), $data);

        return response()->noContent();
    }

    public function downloadEvidence(Request $request, string $token, string $document)
    {
        $this->permit($request);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        $move = DB::table('matricula_movimiento_soportes as s')->join('matricula_movimientos as m', 'm.id', '=', 's.movimiento_id')
            ->where('m.matricula_id', $enrollment->id)->where('s.archivo_token', $document)->value('m.version');
        abort_if($move === null, 404);
        $file = StoredFilePublicToken::findForTenant((string) tenant()->getTenantKey(), $document) ?? abort(404);
        AuditLogger::tenant($request->user(), 'READ', 'matricula_soportes', (string) $enrollment->id, null, ['movimiento' => $move]);

        return Storage::disk($file->disk)->download($file->path, $file->original_name,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function consolidations(Request $request, string $token, SplitPeriodGrades $service)
    {
        $this->permit($request);
        abort_unless($request->user()->canAny([EntryAssessments::REGISTER, EntryAssessments::APPROVE]), 403);
        $student = $this->intake->resolve('matricula', $token, Matricula::query());
        return response()->json(['casos' => $service->details($student),
            'puede_asignar_apoyo' => $request->user()->can(EntryAssessments::REGISTER) && $student->estado === 'activa' && $student->anoLectivo->estado !== 'cerrado',
            'puede_aprobar' => $request->user()->can(EntryAssessments::APPROVE) && $student->estado === 'activa' && $student->anoLectivo->estado !== 'cerrado']);
    }

    private function consolidationRules(): array
    {
        return ['pesos' => 'required|array|list|min:2', 'pesos.*' => 'required|numeric|gt:0|max:100|decimal:0,2',
            'version' => 'required|string|size:64'];
    }

    public function previewConsolidation(Request $request, string $token, string $result, SplitPeriodGrades $service)
    {
        $this->permit($request);
        abort_unless($request->user()->canAny([EntryAssessments::REGISTER, EntryAssessments::APPROVE]), 403);
        $student = $this->intake->resolve('matricula', $token, Matricula::query());
        return response()->json($service->preview($student, $result, $request->validate($this->consolidationRules())));
    }

    public function assignSupport(Request $request, string $token, string $result, SplitPeriodGrades $service)
    {
        $this->permit($request);
        abort_unless($request->user()->can(EntryAssessments::REGISTER), 403);
        $student = $this->intake->resolve('matricula', $token, Matricula::query());
        $data = $request->validate(['actividad_token' => 'required|string|max:64', 'motivo' => 'required|string|max:1500',
            'fecha_entrega' => 'required|date_format:Y-m-d']);
        $service->assignSupport($student, $result, $data, $request->user());
        return response()->noContent();
    }

    public function approveConsolidation(Request $request, string $token, string $result, SplitPeriodGrades $service)
    {
        $this->permit($request);
        abort_unless($request->user()->can(EntryAssessments::APPROVE), 403);
        $student = $this->intake->resolve('matricula', $token, Matricula::query());
        $data = $request->validate([...$this->consolidationRules(), 'motivo' => 'required|string|max:1500']);
        $service->approve($student, $result, $data, $request->user());
        return response()->noContent();
    }

    public function entryAssessments(Request $request, string $token, EntryAssessments $service)
    {
        $this->permit($request);
        abort_unless(EntryAssessments::visible($request->user()), 403);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        AuditLogger::tenant($request->user(), 'READ', 'matricula_valoracion_ingreso', (string) $enrollment->id);
        return response()->json($service->details($enrollment, $request->user()));
    }

    private function assessmentGradeRules(): array
    {
        return ['version_escala' => 'required|string|size:64', 'valor' => ['nullable', 'regex:/^-?\d{1,8}(?:\.\d{1,4})?$/D'],
            'opcion_token' => 'nullable|string|size:24'];
    }

    public function closedAuthorizations(Request $request, string $token, ClosedGradeAuthorizations $service)
    {
        $this->permit($request);
        abort_unless(ClosedGradeAuthorizations::visible($request->user()), 403);
        $student = $this->intake->resolve('matricula', $token, Matricula::query());
        return response()->json($service->details($student, $request->user()));
    }

    public function authorizeClosedGrade(Request $request, string $token, ClosedGradeAuthorizations $service)
    {
        $this->permit($request);
        abort_unless($request->user()->can(ClosedGradeAuthorizations::AUTHORIZE), 403);
        $student = $this->intake->resolve('matricula', $token, Matricula::query());
        $data = $request->validate(['solicitud_token' => 'required|uuid', 'materia_token' => 'required|string|size:24', 'periodo_token' => 'required|string|size:24',
            'rectifica_token' => 'sometimes|required|string|size:24', 'version' => $request->has('rectifica_token') ? 'required|integer|min:0|max:2147483646' : 'prohibited',
            'responsable_token' => 'required|string|size:24', 'motivo' => 'required|string|max:1500', 'soporte' => EnrollmentEvidenceFile::rules()]);
        $service->authorize($student, $request->user(), $data);
        return response()->noContent();
    }

    public function registerClosedGrade(Request $request, string $token, string $grant, ClosedGradeAuthorizations $service)
    {
        $this->permit($request);
        abort_unless($request->user()->canAny([EntryAssessments::REGISTER, EntryAssessments::RECTIFY]), 403);
        $student = $this->intake->resolve('matricula', $token, Matricula::query());
        $data = $request->validate([...$this->assessmentGradeRules(), 'materia_token' => 'prohibited',
            'periodo_token' => 'prohibited', 'matricula_token' => 'prohibited']);
        $service->register($student, $grant, $request->user(), $data);
        return response()->noContent();
    }

    public function revokeClosedAuthorization(Request $request, string $token, string $grant, ClosedGradeAuthorizations $service)
    {
        $this->permit($request);
        abort_unless($request->user()->can(ClosedGradeAuthorizations::AUTHORIZE), 403);
        $student = $this->intake->resolve('matricula', $token, Matricula::query());
        $data = $request->validate(['motivo' => 'required|string|max:1500']);
        $service->revoke($student, $grant, $request->user(), $data['motivo']);
        return response()->noContent();
    }

    public function downloadClosedGradeSupport(Request $request, string $token, string $document)
    {
        $this->permit($request);
        abort_unless(ClosedGradeAuthorizations::visible($request->user()), 403);
        $student = $this->intake->resolve('matricula', $token, Matricula::query());
        abort_unless(ClosedGradeAuthorizations::records($student, $request->user())->where('archivo_token', $document)->exists(), 404);
        return $this->downloadGradeEvidence($request, $student, $document, 'autorizacion_definitiva_cerrada');
    }

    public function registerAssessment(Request $request, string $token, EntryAssessments $service)
    {
        $this->permit($request);
        abort_unless($request->user()->can(EntryAssessments::REGISTER), 403);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        $data = $request->validate([...$this->assessmentGradeRules(), 'materia_token' => 'required|string|size:24',
            'periodo_token' => 'required|string|size:24', 'fecha_ingreso' => 'required_without:traslado_token|prohibits:traslado_token|date_format:Y-m-d',
            'traslado_token' => 'sometimes|required|string|size:24',
            'motivo' => 'required|string|max:1500', 'soporte' => EnrollmentEvidenceFile::rules()]);
        $service->register($enrollment, $data, $request->user());
        return response()->noContent();
    }

    public function approveAssessment(Request $request, string $token, string $result, EntryAssessments $service)
    {
        $this->permit($request);
        abort_unless($request->user()->can(EntryAssessments::APPROVE), 403);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        $data = $request->validate([...$this->assessmentGradeRules(), 'criterio' => 'required|string|max:1500']);
        $service->approve($enrollment, $result, $data, $request->user());
        return response()->noContent();
    }

    public function downloadAssessmentEvidence(Request $request, string $token, string $document)
    {
        $this->permit($request);
        abort_unless(EntryAssessments::visible($request->user()), 403);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        abort_unless(EntryAssessments::readable($enrollment, $request->user())->where('archivo_token', $document)->exists(), 404);
        return $this->downloadGradeEvidence($request, $enrollment, $document, 'matricula_valoracion_ingreso');
    }

    public function externalGrades(Request $request, string $token, CertifiedExternalGrades $grades)
    {
        $this->permit($request);
        abort_unless(CertifiedExternalGrades::visible($request->user()), 403);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        AuditLogger::tenant($request->user(), 'READ', 'matricula_definitivas_externas', (string) $enrollment->id);

        return response()->json($grades->details($enrollment, $request->user()));
    }

    private function followUpRules(): array
    {
        return ['materia_token' => 'required|string', 'periodos' => 'required|array|list|min:1|max:100',
            'periodos.*' => 'required|string|distinct:strict', 'responsable_token' => 'required|string', 'fecha_seguimiento' => 'required|date_format:Y-m-d'];
    }

    public function markWithoutEquivalence(Request $request, string $token, string $document, PendingCertificates $service)
    {
        $this->permit($request);
        abort_unless($request->user()->can(CertifiedExternalGrades::APPROVE), 403);
        $student = $this->intake->resolve('matricula', $token, Matricula::query());
        $data = $request->validate([...$this->followUpRules(), 'motivo' => 'required|string|max:1500']);
        $service->register($student, $data, $request->user(), $document);
        return response()->noContent();
    }

    public function registerPendingCertificate(Request $request, string $token, PendingCertificates $service)
    {
        $this->permit($request);
        abort_unless($request->user()->can(CertifiedExternalGrades::REGISTER), 403);
        $student = $this->intake->resolve('matricula', $token, Matricula::query());
        $data = $request->validate([...$this->followUpRules(), 'fecha_ingreso' => 'required|date_format:Y-m-d']);
        $service->register($student, $data, $request->user());
        return response()->noContent();
    }

    public function registerExternalGrades(Request $request, string $token, CertifiedExternalGrades $grades)
    {
        $this->permit($request);
        abort_unless($request->user()->can(CertifiedExternalGrades::REGISTER), 403);
        $data = $request->validate(['certificado' => EnrollmentEvidenceFile::rules(), 'fecha_ingreso' => 'required|date_format:Y-m-d',
            'colegio_origen' => 'required|string|max:255', 'grado_origen' => 'required|string|max:150', 'escala_origen' => 'required|string|max:255',
            'resultados' => 'required|array|list|min:1|max:100', 'resultados.*' => 'required|array:materia_origen,periodo_origen,desde,hasta,valor_original,pendiente_token',
            'resultados.*.pendiente_token' => 'nullable|string|max:24',
            'resultados.*.materia_origen' => 'required|string|max:150', 'resultados.*.periodo_origen' => 'required|string|max:150',
            'resultados.*.valor_original' => 'required|string|max:120', 'resultados.*.desde' => 'required|date_format:Y-m-d',
            'resultados.*.hasta' => 'required|date_format:Y-m-d']);
        $grades->register($this->intake->resolve('matricula', $token, Matricula::query()), $data, $request->user());

        return response()->noContent();
    }

    public function approveExternalGrade(Request $request, string $token, string $result, CertifiedExternalGrades $grades)
    {
        $this->permit($request);
        abort_unless($request->user()->can(CertifiedExternalGrades::APPROVE), 403);
        $data = $request->validate(['materia_token' => 'required|string|size:24', 'periodo_token' => 'required|string|size:24',
            'version_escala' => 'required|string|size:64', 'valor' => ['nullable', 'regex:/^-?\d{1,8}(?:\.\d{1,4})?$/D'],
            'opcion_token' => 'nullable|string|size:24', 'criterio' => 'required|string|max:1500']);
        $grades->approve($this->intake->resolve('matricula', $token, Matricula::query()), $result, $data, $request->user());

        return response()->noContent();
    }

    public function choosePeriodGrade(Request $request, string $token, string $result, GradeSourceDecisions $choices)
    {
        $this->permit($request);
        abort_unless($request->user()->can(GradeSourceDecisions::CHOOSE), 403);
        $data = $request->validate(['fuente' => 'required|in:colegio,certificado', 'motivo' => 'required|string|max:1500',
            'huella' => 'required|string|size:64', 'version' => 'required|integer|min:0|max:2147483646']);
        $choices->choose($this->intake->resolve('matricula', $token, Matricula::query()), $result, $data, $request->user());
        return response()->noContent();
    }

    public function rectifyExternalGrade(Request $request, string $token, string $result, CertifiedExternalGrades $grades)
    {
        return $this->rectifyGrade($request, $token, $result, $grades);
    }

    public function downloadExternalRectification(Request $request, string $token, string $result, string $document, CertifiedExternalGrades $grades)
    {
        return $this->downloadRectification($request, $token, $result, $document, $grades);
    }

    public function rectifyAssessment(Request $request, string $token, string $result, EntryAssessments $grades)
    {
        return $this->rectifyGrade($request, $token, $result, $grades);
    }

    public function downloadAssessmentRectification(Request $request, string $token, string $result, string $document, EntryAssessments $grades)
    {
        return $this->downloadRectification($request, $token, $result, $document, $grades);
    }

    private function rectifyGrade(Request $request, string $token, string $result, CertifiedExternalGrades|EntryAssessments $grades)
    {
        $this->permit($request);
        abort_unless($request->user()->can($grades::RECTIFY), 403);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        $data = $request->validate(['version' => 'required|integer|min:0|max:2147483646',
            'valor' => ['nullable', 'regex:/^-?\d{1,8}(?:\.\d{1,4})?$/D'], 'opcion_token' => 'nullable|string|size:24',
            'motivo' => 'required|string|max:1500', 'soporte' => EnrollmentEvidenceFile::rules()]);
        $grades->rectify($enrollment, $result, $data, $request->user());
        return response()->noContent();
    }

    private function downloadRectification(Request $request, string $token, string $result, string $document, CertifiedExternalGrades|EntryAssessments $grades)
    {
        $this->permit($request);
        abort_unless($grades::visible($request->user()), 403);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        abort_unless(DB::table($grades::REVISIONS.' as h')->join($grades::RESULTS.' as r', 'r.id', '=', 'h.resultado_id')
            ->when($grades instanceof EntryAssessments, fn ($q) => $q->whereIn('r.id', EntryAssessments::readable($enrollment, $request->user())->select('id')))
            ->where('r.matricula_id', $enrollment->id)->where('r.token', $result)->where('h.archivo_token', $document)->exists(), 404);
        return $this->downloadGradeEvidence($request, $enrollment, $document, $grades::RECTIFICATION_RESOURCE);
    }

    public function downloadCertificate(Request $request, string $token, string $document)
    {
        $this->permit($request);
        abort_unless(CertifiedExternalGrades::visible($request->user()), 403);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        abort_unless(DB::table(CertifiedExternalGrades::CERTIFICATES)->where('matricula_id', $enrollment->id)
            ->where('archivo_token', $document)->exists(), 404);
        return $this->downloadGradeEvidence($request, $enrollment, $document, 'matricula_certificado_externo');
    }

    private function downloadGradeEvidence(Request $request, Matricula $enrollment, string $document, string $resource)
    {
        $file = StoredFilePublicToken::findForTenant((string) tenant()->getTenantKey(), $document) ?? abort(404);
        AuditLogger::tenant($request->user(), 'READ', $resource, (string) $enrollment->id, null, ['archivo' => $document]);

        return Storage::disk($file->disk)->download($file->path, $file->original_name,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function activityGrades(Request $request, string $token, EnrollmentTrajectory $trajectory)
    {
        $this->permit($request);
        abort_unless(app(GradebookService::class)->manages($request->user()), 403);
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        $grades = $trajectory->activityGrades($enrollment);
        AuditLogger::tenant($request->user(), 'READ', 'matricula_notas_historicas', (string) $enrollment->id);

        return response()->json(['data' => $grades->items(), 'hay_mas' => $grades->hasMorePages()]);
    }

    public function download(Request $request, string $token, string $document)
    {
        $this->permit($request);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::query());
        $application = Solicitud::where('matricula_id', $enrollment->id)->firstOrFail();
        $doc = $this->intake->resolve('ingreso-documento', $document, Documento::where('solicitud_id', $application->id));
        $file = StoredFile::where('tenant_id', tenant()->getTenantKey())->findOrFail($doc->archivo_id);
        AuditLogger::tenant($request->user(), 'READ', 'ingreso-documento', (string) $doc->id);

        return Storage::disk($file->disk)->download($file->path, $file->original_name,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function geography(Request $request)
    {
        $this->permit($request);

        return response()->json(['departamentos' => EnrollmentSubmission::geography()]);
    }

    public function store(Request $request, EnrollmentSubmission $submission)
    {
        $this->permit($request);
        $data = $request->validate(['campana_token' => 'required|string|size:24', 'grupo_token' => 'required|string|size:24',
            'email' => 'required|email|max:255', 'envio_token' => 'required|uuid']);
        $created = [];
        try {
            $enrollment = DB::transaction(function () use ($request, $submission, $data, &$created) {
                $campaign = $this->intake->resolve('ingreso-campana', $data['campana_token'], Campana::query());
                $year = AnoLectivo::lockForUpdate()->findOrFail($campaign->ano_lectivo_id);
                abort_unless(in_array($year->estado, ['planificado', 'en_curso'], true), 422, 'El año está cerrado.');
                $campaign = Campana::lockForUpdate()->findOrFail($campaign->id);
                $group = $this->intake->resolve('grupo', $data['grupo_token'], Grupo::where('ano_lectivo_id', $year->id)->where('estado', 'activo'));
                $email = Str::lower(trim($data['email']));
                $existing = Solicitud::where('campana_id', $campaign->id)->where('email', $email)->first();
                if ($existing && DB::table('ingreso_envios')->where('solicitud_id', $existing->id)->where('envio_token', $data['envio_token'])->exists()) {
                    $saved = Matricula::findOrFail($existing->matricula_id);
                    abort_unless($saved->grupo_id === $group->id, 409, 'El envío ya se registró en otro grupo.');

                    return $saved;
                }
                abort_if($existing, 422, 'Ya existe una solicitud en esta convocatoria para ese correo. Revísala antes de matricular.');
                abort_if(app()->environment('production') && ! app(SchoolMail::class)->ready(), 422, 'Configura el correo institucional para entregar el acceso.');
                $application = Solicitud::create(['campana_id' => $campaign->id, 'grado_id' => $group->grado_id, 'email' => $email,
                    'pin_hash' => Hash::make(Str::random(32)), 'pin_expira' => now(), 'sesion_version' => Str::random(64)]);
                // The staff member attests receipt of authorization; this is NOT email verification.
                $submission->submit($request, $application, $created);
                $application->documentos()->update(['estado' => 'aprobado', 'revisor_id' => $request->user()->id]);
                $this->intake->event($application, 'registro_manual', 'Documentos y autorización recibidos por el colegio.', $request->user());
                $application = $this->intake->decide($application, $request->user(), ['estado' => 'aprobada',
                    'grado_token' => OpaqueUrlToken::for('grado', $group->grado_id)], manual: true);
                $this->intake->distribute($campaign, [['solicitud_token' => OpaqueUrlToken::for('ingreso-solicitud', $application->id),
                    'grupo_token' => $data['grupo_token']]], true, $request->user());
                if ($group->sede_id) {
                    User::whereKey($application->estudiante_id)->update(['sede_id' => $group->sede_id]);
                }

                return Matricula::findOrFail($application->fresh()->matricula_id);
            });
        } catch (\Throwable $error) {
            foreach ($created as $file) {
                Storage::disk($file->disk)->delete($file->path);
                $file->forceDelete();
            }
            throw $error;
        }

        return response()->json(['data' => $this->present($enrollment), 'correo_operativo' => app(SchoolMail::class)->ready()], 201);
    }
}
