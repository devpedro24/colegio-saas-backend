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
use App\Services\Ingreso\EnrollmentIntake;
use App\Services\Ingreso\EnrollmentSubmission;
use App\Services\SchoolMail;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Enrollment administration deliberately has no gradebook/report-card actions. */
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
            'campana_token' => 'nullable|string|size:24', 'buscar' => 'nullable|string|max:120',
            'origen' => ['nullable', Rule::in(['manual', 'enlace'])], 'page' => 'sometimes|integer|min:1']);
        $years = AnoLectivo::orderByDesc('nombre')->get();
        $year = ! empty($data['ano_token']) ? $this->intake->resolve('ano-lectivo', $data['ano_token'], AnoLectivo::query())
            : ($years->firstWhere('estado', 'en_curso') ?? $years->first());
        $groups = Grupo::with('grado', 'sede')->where('ano_lectivo_id', $year?->id)->orderBy('nombre')->get();
        $campaigns = Campana::where('ano_lectivo_id', $year?->id)->orderByDesc('id')->get();
        $query = Matricula::with('estudiante', 'grupo.grado', 'grupo.sede')->where('ano_lectivo_id', $year?->id);
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

    public function show(Request $request, string $token)
    {
        $this->permit($request);
        $enrollment = $this->intake->resolve('matricula', $token, Matricula::with('estudiante', 'grupo.grado', 'grupo.sede'));
        $application = Solicitud::where('matricula_id', $enrollment->id)->first();
        $profile = DB::table('ingreso_perfiles')->where('estudiante_id', $enrollment->estudiante_id)->first();
        AuditLogger::tenant($request->user(), 'READ', 'matricula', (string) $enrollment->id);

        return response()->json(['data' => [...$this->present($enrollment),
            'datos' => $application?->datos ?? ($profile ? json_decode($profile->datos, true) : []),
            'documentos' => $application ? $this->intake->present($application)['documentos'] : []]]);
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
