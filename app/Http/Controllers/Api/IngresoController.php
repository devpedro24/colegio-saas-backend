<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Ingreso\Campana;
use App\Models\Ingreso\Documento;
use App\Models\Ingreso\Notificacion;
use App\Models\Ingreso\Solicitud;
use App\Models\StoredFile;
use App\Services\Ingreso\EnrollmentIntake;
use App\Services\Ingreso\EnrollmentSettings;
use App\Services\Ingreso\EnrollmentWorkflow;
use App\Services\SchoolMail;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class IngresoController extends Controller
{
    public function __construct(private EnrollmentIntake $intake) {}

    private function permit(Request $r, string $action): void
    {
        $this->intake->requirePlan();
        abort_unless($r->user()->can('ingreso.'.$action), 403);
    }

    private function campaign(string $token): Campana
    {
        return $this->intake->resolve('ingreso-campana', $token, Campana::query());
    }

    private function application(string $token): Solicitud
    {
        return $this->intake->resolve('ingreso-solicitud', $token, Solicitud::query());
    }

    public function catalog(Request $r)
    {
        $this->intake->requirePlan();
        abort_unless(collect(['ver', 'configurar', 'revisar', 'decidir', 'asignar'])->contains(fn ($p) => $r->user()->can('ingreso.'.$p)), 403);

        return response()->json(['correo_operativo' => app(SchoolMail::class)->ready(),
            'conteos' => $r->user()->can('ingreso.ver')
                ? Solicitud::selectRaw('estado, count(*) as total')->groupBy('estado')->pluck('total', 'estado')
                : [],
            'zona_horaria' => tenant()->zonaHorariaInstitucional(),
            'anos' => AnoLectivo::whereIn('estado', ['planificado', 'en_curso'])->orderByDesc('nombre')->get()->map(fn ($y) => [
                'token' => OpaqueUrlToken::for('ano-lectivo', $y->id), 'nombre' => $y->nombre]),
            'grados' => Grado::where('estado', 'activo')->get()->map(fn ($g) => ['token' => OpaqueUrlToken::for('grado', $g->id),
                'regla_token' => $g->ingreso_grado_id ? OpaqueUrlToken::for('ingreso-grado', $g->ingreso_grado_id) : null,
                'ano_token' => OpaqueUrlToken::for('ano-lectivo', $g->ano_lectivo_id), 'nombre' => $g->nombre]),
            'campanas' => Campana::orderByDesc('id')->limit(100)->get()->map(fn ($campaign) => $this->intake->campaign($campaign))]);
    }

    public function saveCampaign(Request $r, ?string $campaign = null)
    {
        $this->permit($r, 'configurar');
        $d = $r->validate(['nombre' => 'required|string|max:120', 'ano_token' => 'required|string|size:24',
            'apertura_en' => 'nullable|date_format:Y-m-d\TH:i',
            'cierre_en' => ['nullable', 'date_format:Y-m-d\TH:i', Rule::when($r->filled('apertura_en'), 'after_or_equal:apertura_en')],
            'correcciones_hasta' => ['nullable', 'date_format:Y-m-d\TH:i', Rule::when($r->filled('cierre_en'), 'after_or_equal:cierre_en')],
            'abierta' => 'required|boolean', 'desde' => 'required|date_format:Y-m-d', 'hasta' => 'present|nullable|date_format:Y-m-d|after_or_equal:desde',
            'configuracion' => 'required|array:grados,documentos,campos,privacidad,pin_expira_en,documento_identidad,pin_manual',
            'configuracion.pin_manual' => 'sometimes|boolean',
            'configuracion.documento_identidad' => 'sometimes|nullable|string|max:50',
            'configuracion.privacidad' => 'sometimes|nullable|string|min:20|max:5000',
            'configuracion.pin_expira_en' => 'present|nullable|date_format:Y-m-d\TH:i',
            'configuracion.grados' => 'required|array|min:1|max:100',
            'configuracion.grados.*' => 'array:token,nombre,cupo,edad_min,edad_max', 'configuracion.grados.*.token' => 'required|string|size:24|distinct',
            'configuracion.grados.*.edad_min' => 'sometimes|nullable|integer|min:1|max:120',
            'configuracion.grados.*.edad_max' => 'sometimes|nullable|integer|min:1|max:120',
            'configuracion.grados.*.nombre' => 'required|string|max:120', 'configuracion.grados.*.cupo' => 'present|nullable|integer|min:1|max:10000',
            'configuracion.documentos' => 'present|array|max:30',
            'configuracion.documentos.*' => 'array:key,nombre,instrucciones,vigencia,obligatorio,formatos,grados',
            'configuracion.documentos.*.key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,49}$/|distinct',
            'configuracion.documentos.*.nombre' => 'required|string|max:120',
            'configuracion.documentos.*.instrucciones' => 'required|string|max:1000',
            'configuracion.documentos.*.vigencia' => 'nullable|string|max:250',
            'configuracion.documentos.*.obligatorio' => 'required|boolean',
            'configuracion.documentos.*.formatos' => 'required|array|min:1',
            'configuracion.documentos.*.formatos.*' => ['required', Rule::in(['pdf', 'jpg', 'png', 'docx'])],
            'configuracion.documentos.*.grados' => 'present|array', 'configuracion.documentos.*.grados.*' => 'string|size:24',
            'configuracion.campos' => 'present|array|max:20', 'configuracion.campos.*' => 'array:key,nombre,obligatorio,tipo,opciones',
            'configuracion.campos.*.key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,49}$/|distinct',
            'configuracion.campos.*.nombre' => 'required|string|max:120', 'configuracion.campos.*.obligatorio' => 'required|boolean',
            'configuracion.campos.*.opciones' => 'sometimes|array|max:30', 'configuracion.campos.*.opciones.*' => 'required|string|max:120|distinct',
            'configuracion.campos.*.tipo' => ['required', Rule::in(['texto', 'fecha', 'numero', 'telefono', 'email', 'seleccion'])]]);
        foreach ($d['configuracion']['campos'] as $field) {
            abort_if($field['tipo'] === 'seleccion' && empty($field['opciones']), 422, __('Agrega al menos una opción al campo de selección.'));
        }
        $d['configuracion']['pin_manual'] ??= true;
        if ($d['configuracion']['pin_expira_en'] !== null) {
            $pinDeadline = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $d['configuracion']['pin_expira_en'], tenant()->zonaHorariaInstitucional());
            abort_unless($pinDeadline && $pinDeadline->isFuture(), 422, __('La fecha de vencimiento del PIN debe estar en el futuro.'));
        }
        $year = $this->intake->resolve('ano-lectivo', $d['ano_token'], AnoLectivo::whereIn('estado', ['planificado', 'en_curso']));
        foreach ($d['configuracion']['grados'] as &$g) {
            $grade = $this->intake->resolve('grado', $g['token'], Grado::where('ano_lectivo_id', $year->id)->where('estado', 'activo'));
            $g['nombre'] = $grade->nombre;
        }
        unset($g);
        $identity = $d['configuracion']['documento_identidad'] ?? null;
        if ($identity) {
            $requirement = collect($d['configuracion']['documentos'])->firstWhere('key', $identity);
            abort_unless($requirement && $requirement['obligatorio'] && ! $requirement['grados']
                && ! array_diff($requirement['formatos'], ['pdf', 'jpg', 'png']), 422, __('intake.identityConfiguration'));
        }
        abort_if($d['abierta'] && app()->environment('production') && ! app(SchoolMail::class)->ready(),
            422, __('Configura el correo saliente antes de abrir una convocatoria en producción.'));
        foreach ($d['configuracion']['documentos'] as $doc) {
            abort_if(array_diff($doc['grados'], array_column($d['configuracion']['grados'], 'token')), 422, __('Un requisito tiene grados ajenos a la campaña.'));
        }
        $c = DB::transaction(function () use ($campaign, $d, $year, $r) {
            $c = $campaign ? $this->intake->resolve('ingreso-campana', $campaign, Campana::lockForUpdate()) : new Campana;
            $institution = app(EnrollmentSettings::class)->record();
            // Ranges are school settings. Never write displayed effective ranges back to a campaign.
            $d['configuracion']['grados'] = array_map(function ($grade) use ($c) {
                unset($grade['edad_min'], $grade['edad_max']);
                $legacy = collect($c->configuracion['grados'] ?? [])->firstWhere('token', $grade['token']) ?? [];

                return $grade + array_intersect_key($legacy, array_flip(['edad_min', 'edad_max', 'edad_corte']));
            }, $d['configuracion']['grados']);
            $d['configuracion']['privacidad'] = $institution->version > 0 ? $institution->privacidad
                : ($c->configuracion['privacidad'] ?? $d['configuracion']['privacidad'] ?? $institution->privacidad);
            if (! is_string($d['configuracion']['privacidad']) || mb_strlen(trim($d['configuracion']['privacidad'])) < 20) {
                throw ValidationException::withMessages(['configuracion.privacidad' => __('intake.privacyNotConfigured')]);
            }
            // Existing requests keep their requirements while the next ones use the updated form.
            if ($c->exists && Solicitud::where('campana_id', $c->id)->exists()) {
                Solicitud::where('campana_id', $c->id)->whereNull('requisitos_snapshot')->update(['requisitos_snapshot' => json_encode($c->configuracion)]);
                abort_if($c->ano_lectivo_id !== $year->id,
                    422, __('La campaña ya tiene solicitudes: conserva sus requisitos o crea otra campaña. Puedes ajustar las fechas y abrir/cerrar.'));
            }
            $c->fill(['nombre' => $d['nombre'], 'ano_lectivo_id' => $year->id, 'abierta' => $d['abierta'],
                'desde' => $d['desde'], 'hasta' => $d['hasta'], 'configuracion' => $d['configuracion']])->save();
            foreach (['apertura_en', 'cierre_en', 'correcciones_hasta'] as $field) {
                if (array_key_exists($field, $d)) {
                    $c->{$field} = $d[$field] ? CarbonImmutable::parse($d[$field], tenant()->zonaHorariaInstitucional())->utc() : null;
                }
            }
            $c->save();
            AuditLogger::tenant($r->user(), 'UPDATE', 'ingreso-campana', (string) $c->id, null, ['nombre' => $c->nombre, 'abierta' => $c->abierta]);

            return $c;
        });

        return response()->json(['data' => $this->intake->campaign($c)]);
    }

    public function applications(Request $r, ?string $campaign = null)
    {
        $this->permit($r, 'ver');
        $c = $campaign ? $this->campaign($campaign) : null;
        $r->validate(['page' => 'sometimes|integer|min:1', 'buscar' => 'nullable|string|max:100', 'grado_token' => 'nullable|string|size:24',
            'estado' => ['nullable', Rule::in(['pin_pendiente', 'borrador', 'enviada', 'revision', 'correcciones', 'validada', 'espera', 'aprobada', 'rechazada', 'anulada', 'matriculada'])]]);
        $grade = $r->filled('grado_token') ? $this->intake->resolve('grado', $r->grado_token, Grado::query()) : null;
        $query = Solicitud::with('campana')->when($c, fn ($q) => $q->where('campana_id', $c->id))
            ->when($grade, fn ($q) => $q->where('grado_id', $grade->id));
        if ($r->filled('buscar')) {
            $term = mb_strtolower($r->string('buscar')->trim()->toString());
            $query->where(function ($query) use ($term) {
                $query->whereRaw('lower(email) like ?', ['%'.$term.'%'])
                    ->orWhereIn('id', function ($tokens) use ($term) {
                        $tokens->select('record_id')->from(\App\Support\AcademicTokenIndex::TABLE)
                            ->where('resource', 'ingreso-solicitud')
                            ->whereRaw('lower(substr(token, 1, 10)) like ?', ['%'.preg_replace('/^mat-/', '', $term).'%']);
                    })
                    ->orWhereRaw('lower(cast(datos as text)) like ?', ['%'.$term.'%']);
            });
        }
        $counts = (clone $query)->selectRaw('estado, count(*) as total')->groupBy('estado')->pluck('total', 'estado');
        $query->when($r->filled('estado'), fn ($q) => $q->where('estado', $r->estado));
        $p = $query->orderByDesc('id')->paginate(30);

        return response()->json(['data' => $p->getCollection()->map(fn ($s) => $this->intake->present($s, false)),
            'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total(),
            'conteos' => $counts,
            'grupos' => Grupo::with('grado')->when($c, fn ($q) => $q->where('ano_lectivo_id', $c->ano_lectivo_id))->where('estado', 'activo')->get()->map(fn ($g) => [
                'token' => OpaqueUrlToken::for('grupo', $g->id), 'grado_token' => OpaqueUrlToken::for('grado', $g->grado_id),
                'nombre' => $g->grado->nombre.' / '.$g->nombre, 'cupo' => $g->cupo_maximo])]);
    }

    public function show(Request $r, string $application)
    {
        $this->permit($r, 'ver');

        $solicitud = $this->application($application);

        return response()->json(['data' => $this->intake->present($solicitud),
            'grupos' => Grupo::with('grado')->where('ano_lectivo_id', $solicitud->campana->ano_lectivo_id)
                ->where('grado_id', $solicitud->grado_id)->where('estado', 'activo')->get()->map(fn ($grupo) => [
                    'token' => OpaqueUrlToken::for('grupo', $grupo->id),
                    'grado_token' => OpaqueUrlToken::for('grado', $grupo->grado_id),
                    'nombre' => $grupo->grado->nombre.' / '.$grupo->nombre, 'cupo' => $grupo->cupo_maximo,
                ])]);
    }

    public function activatePin(Request $request, string $application, EnrollmentWorkflow $workflow)
    {
        $this->permit($request, 'ver');
        abort_unless($request->user()->can('ingreso.revisar') || $request->user()->can('ingreso.configurar'), 403);
        $data = $request->validate(['expira_en' => 'present|nullable|date_format:Y-m-d\TH:i']);

        return response()->json($workflow->activate($this->application($application), $request->user(), $data['expira_en']))
            ->header('Cache-Control', 'private, no-store');
    }

    public function correction(Request $request, string $application, EnrollmentWorkflow $workflow)
    {
        $this->permit($request, 'ver');
        $this->permit($request, 'revisar');
        $data = $request->validate(['campo' => 'required|string|max:150', 'motivo' => 'required|string|min:3|max:2000', 'hasta' => 'required|date_format:Y-m-d\TH:i']);

        return response()->json(['data' => $this->intake->present($workflow->correct($this->application($application), $request->user(), $data))]);
    }

    public function validateDocuments(Request $request, string $application, EnrollmentWorkflow $workflow)
    {
        $this->permit($request, 'ver');
        $this->permit($request, 'revisar');

        return response()->json(['data' => $this->intake->present($workflow->validate($this->application($application), $request->user()))]);
    }

    public function notifications(Request $request)
    {
        $this->permit($request, 'ver');

        return response()->json(['data' => Notificacion::orderByDesc('id')->limit(200)->get()->map(fn ($notice) => [
            'key' => hash('sha256', $notice->clave), 'email' => $notice->email, 'asunto' => $notice->asunto,
            'enviada_en' => $notice->enviada_en?->toIso8601String(), 'creada_en' => $notice->created_at?->toIso8601String(),
            'contenido' => preg_match('/pin|credenciales/', $notice->clave) ? null : $notice->contenido,
        ])]);
    }

    public function review(Request $r, string $application, string $document)
    {
        $this->permit($r, 'revisar');
        $this->permit($r, 'ver');
        $d = $r->validate(['estado' => ['required', Rule::in(['aprobado', 'rechazado'])],
            'observacion' => 'required_if:estado,rechazado|nullable|string|max:2000']);
        $s = DB::transaction(function () use ($r, $application, $document, $d) {
            $s = $this->intake->resolve('ingreso-solicitud', $application, Solicitud::lockForUpdate());
            abort_if($s->pin_manual && $d['estado'] === 'rechazado', 422, __('Solicita la corrección específica con su motivo y plazo.'));
            abort_unless(in_array($s->estado, EnrollmentIntake::REVIEWABLE, true), 422, __('La solicitud no está en revisión.'));
            $doc = $this->intake->resolve('ingreso-documento', $document, Documento::where('solicitud_id', $s->id));
            abort_if($doc->estado === 'retirado', 422, __('Este documento fue retirado por el solicitante.'));
            abort_if($s->documentos()->where('requisito', $doc->requisito)->where('version', '>', $doc->version)->exists(), 422, __('Revisa la versión más reciente.'));
            if ($doc->estado === $d['estado'] && $doc->observacion === ($d['observacion'] ?? null)) {
                return $s;
            }
            $doc->update([...$d, 'revisor_id' => $r->user()->id]);
            $s->update(['validada_en' => null, 'estado' => $d['estado'] === 'rechazado' ? 'correcciones' : ($s->estado === 'correcciones' ? 'correcciones' : 'revision')]);
            $this->intake->event($s, 'documento_'.$d['estado'], $doc->nombre.': '.($d['observacion'] ?? ''), $r->user());
            if ($d['estado'] === 'rechazado') {
                $this->intake->notify($s, 'documento:'.Str::uuid(), 'Debes corregir un documento de matrícula',
                    $doc->nombre."\nMotivo: ".$d['observacion']);
            }

            return $s;
        });

        return response()->json(['data' => $this->intake->present($s)]);
    }

    public function decide(Request $r, string $application)
    {
        $this->permit($r, 'decidir');
        $this->permit($r, 'ver');
        $d = $r->validate(['estado' => ['required', Rule::in(['aprobada', 'rechazada', 'espera', 'correcciones', 'anulada'])],
            'grado_token' => 'required_if:estado,aprobada|nullable|string|size:24', 'observacion' => 'nullable|string|max:2000']);

        $candidate = $this->application($application);
        if ($d['estado'] === 'anulada') {
            $r->validate(['observacion' => 'required|string|min:3|max:2000']);

            return response()->json(['data' => $this->intake->present(app(EnrollmentWorkflow::class)->cancel($candidate, $r->user(), $d['observacion']))]);
        }
        abort_if($candidate->pin_manual && $d['estado'] === 'correcciones', 422, __('Solicita la corrección específica con su motivo y plazo.'));

        return response()->json(['data' => $this->intake->present($this->intake->decide($candidate, $r->user(), $d))]);
    }

    public function distribute(Request $r, string $campaign)
    {
        $this->permit($r, 'asignar');
        $this->permit($r, 'ver');
        $d = $r->validate(['confirmar' => 'required|boolean', 'asignaciones' => 'required_if:confirmar,true|array|max:300',
            'asignaciones.*.solicitud_token' => 'required|string|size:24|distinct', 'asignaciones.*.grupo_token' => 'required|string|size:24']);

        return response()->json($this->intake->distribute($this->campaign($campaign), $d['asignaciones'] ?? [], $d['confirmar'], $r->user()));
    }

    public function changeGrade(Request $r, string $application)
    {
        $this->permit($r, 'cambiar_grado');
        $this->permit($r, 'ver');
        $d = $r->validate(['grado_token' => 'required|string|size:24', 'observacion' => 'required|string|min:3|max:2000']);
        $s = DB::transaction(function () use ($r, $application, $d) {
            $s = $this->intake->resolve('ingreso-solicitud', $application, Solicitud::lockForUpdate());
            abort_unless(in_array($s->estado, EnrollmentIntake::REVIEWABLE, true), 422, __('La solicitud no admite cambiar de grado.'));
            $this->intake->open($s->campana);
            abort_unless(collect($s->campana->configuracion['grados'])->contains('token', $d['grado_token']), 404);
            $grade = $this->intake->resolve('grado', $d['grado_token'], Grado::where('ano_lectivo_id', $s->campana->ano_lectivo_id)->where('estado', 'activo'));
            $s->update(['grado_aprobado_id' => $grade->id, 'estado' => 'correcciones', 'observacion' => $d['observacion']]);
            $this->intake->event($s, 'cambio_grado', $grade->nombre.': '.$d['observacion'], $r->user());
            $this->intake->notify($s, 'grado:'.Str::uuid(), 'Revisa el grado de tu solicitud', 'El colegio propone '.$grade->nombre.'. '.$d['observacion']);

            return $s;
        });

        return response()->json(['data' => $this->intake->present($s)]);
    }

    public function download(Request $r, string $application, string $document)
    {
        $this->permit($r, 'ver');
        $s = $this->application($application);
        $doc = $this->intake->resolve('ingreso-documento', $document, Documento::where('solicitud_id', $s->id));
        $file = StoredFile::where('tenant_id', tenant()->getTenantKey())->findOrFail($doc->archivo_id);
        AuditLogger::tenant($r->user(), 'READ', 'ingreso-documento', (string) $doc->id);

        if ($r->boolean('vista') && in_array($file->mime, ['application/pdf', 'image/png', 'image/jpeg'], true)) {
            return Storage::disk($file->disk)->response($file->path, $file->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'inline');
        }

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function studentStatus(Request $r)
    {
        $s = Solicitud::where('estudiante_id', $r->user()->id)->where('estado', 'aprobada')->exists();

        return response()->json(['grupo_pendiente' => $s]);
    }
}
