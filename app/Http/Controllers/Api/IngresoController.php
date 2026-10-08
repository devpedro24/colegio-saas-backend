<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Ingreso\Campana;
use App\Models\Ingreso\Documento;
use App\Models\Ingreso\Solicitud;
use App\Models\StoredFile;
use App\Services\Ingreso\EnrollmentIntake;
use App\Services\SchoolMail;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'anos' => AnoLectivo::whereIn('estado', ['planificado', 'en_curso'])->orderByDesc('nombre')->get()->map(fn ($y) => [
                'token' => OpaqueUrlToken::for('ano-lectivo', $y->id), 'nombre' => $y->nombre]),
            'grados' => Grado::where('estado', 'activo')->get()->map(fn ($g) => ['token' => OpaqueUrlToken::for('grado', $g->id),
                'ano_token' => OpaqueUrlToken::for('ano-lectivo', $g->ano_lectivo_id), 'nombre' => $g->nombre]),
            'campanas' => Campana::orderByDesc('id')->limit(100)->get()->map($this->intake->campaign(...))]);
    }

    public function saveCampaign(Request $r, ?string $campaign = null)
    {
        $this->permit($r, 'configurar');
        $d = $r->validate(['nombre' => 'required|string|max:120', 'ano_token' => 'required|string|size:24',
            'abierta' => 'required|boolean', 'desde' => 'required|date_format:Y-m-d', 'hasta' => 'present|nullable|date_format:Y-m-d|after_or_equal:desde',
            'configuracion' => 'required|array:grados,documentos,campos,privacidad',
            'configuracion.privacidad' => 'required|string|min:20|max:5000',
            'configuracion.grados' => 'required|array|min:1|max:100',
            'configuracion.grados.*' => 'array:token,nombre,cupo', 'configuracion.grados.*.token' => 'required|string|size:24|distinct',
            'configuracion.grados.*.nombre' => 'required|string|max:120', 'configuracion.grados.*.cupo' => 'required|integer|min:1|max:10000',
            'configuracion.documentos' => 'present|array|max:30',
            'configuracion.documentos.*' => 'array:key,nombre,instrucciones,obligatorio,formatos,max_mb,grados',
            'configuracion.documentos.*.key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,49}$/|distinct',
            'configuracion.documentos.*.nombre' => 'required|string|max:120',
            'configuracion.documentos.*.instrucciones' => 'required|string|max:1000',
            'configuracion.documentos.*.obligatorio' => 'required|boolean',
            'configuracion.documentos.*.formatos' => 'required|array|min:1',
            'configuracion.documentos.*.formatos.*' => ['required', Rule::in(['pdf', 'jpg', 'png', 'docx'])],
            'configuracion.documentos.*.max_mb' => 'required|integer|min:1|max:10',
            'configuracion.documentos.*.grados' => 'present|array', 'configuracion.documentos.*.grados.*' => 'string|size:24',
            'configuracion.campos' => 'present|array|max:20', 'configuracion.campos.*' => 'array:key,nombre,obligatorio,tipo',
            'configuracion.campos.*.key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,49}$/|distinct',
            'configuracion.campos.*.nombre' => 'required|string|max:120', 'configuracion.campos.*.obligatorio' => 'required|boolean',
            'configuracion.campos.*.tipo' => ['required', Rule::in(['texto', 'fecha'])]]);
        $year = $this->intake->resolve('ano-lectivo', $d['ano_token'], AnoLectivo::whereIn('estado', ['planificado', 'en_curso']));
        foreach ($d['configuracion']['grados'] as &$g) {
            $grade = $this->intake->resolve('grado', $g['token'], Grado::where('ano_lectivo_id', $year->id)->where('estado', 'activo'));
            $g['nombre'] = $grade->nombre;
        }
        unset($g);
        abort_if($d['abierta'] && app()->environment('production') && ! app(SchoolMail::class)->ready(),
            422, __('Configura el correo saliente antes de abrir una convocatoria en producción.'));
        foreach ($d['configuracion']['documentos'] as $doc) {
            abort_if(array_diff($doc['grados'], array_column($d['configuracion']['grados'], 'token')), 422, __('Un requisito tiene grados ajenos a la campaña.'));
        }
        $c = DB::transaction(function () use ($campaign, $d, $year, $r) {
            $c = $campaign ? $this->intake->resolve('ingreso-campana', $campaign, Campana::lockForUpdate()) : new Campana;
            // A frozen schema avoids changing requirements underneath a submitted family form.
            if ($c->exists && Solicitud::where('campana_id', $c->id)->exists()) {
                abort_if($c->configuracion != $d['configuracion'] || $c->ano_lectivo_id !== $year->id,
                    422, __('La campaña ya tiene solicitudes: conserva sus requisitos o crea otra campaña. Puedes ajustar las fechas y abrir/cerrar.'));
            }
            $c->fill(['nombre' => $d['nombre'], 'ano_lectivo_id' => $year->id, 'abierta' => $d['abierta'],
                'desde' => $d['desde'], 'hasta' => $d['hasta'], 'configuracion' => $d['configuracion']])->save();
            AuditLogger::tenant($r->user(), 'UPDATE', 'ingreso-campana', (string) $c->id, null, ['nombre' => $c->nombre, 'abierta' => $c->abierta]);

            return $c;
        });

        return response()->json(['data' => $this->intake->campaign($c)]);
    }

    public function applications(Request $r, string $campaign)
    {
        $this->permit($r, 'ver');
        $c = $this->campaign($campaign);
        $r->validate(['page' => 'sometimes|integer|min:1', 'estado' => ['nullable', Rule::in(['borrador', 'enviada', 'revision', 'correcciones', 'espera', 'aprobada', 'rechazada', 'matriculada'])]]);
        $p = Solicitud::where('campana_id', $c->id)->when($r->filled('estado'), fn ($q) => $q->where('estado', $r->estado))
            ->orderByDesc('id')->paginate(30);

        return response()->json(['data' => $p->getCollection()->map(fn ($s) => $this->intake->present($s, false)),
            'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total(),
            'grupos' => Grupo::with('grado')->where('ano_lectivo_id', $c->ano_lectivo_id)->where('estado', 'activo')->get()->map(fn ($g) => [
                'token' => OpaqueUrlToken::for('grupo', $g->id), 'grado_token' => OpaqueUrlToken::for('grado', $g->grado_id),
                'nombre' => $g->grado->nombre.' / '.$g->nombre, 'cupo' => $g->cupo_maximo])]);
    }

    public function show(Request $r, string $application)
    {
        $this->permit($r, 'ver');

        return response()->json(['data' => $this->intake->present($this->application($application))]);
    }

    public function review(Request $r, string $application, string $document)
    {
        $this->permit($r, 'revisar');
        $this->permit($r, 'ver');
        $d = $r->validate(['estado' => ['required', Rule::in(['aprobado', 'rechazado'])],
            'observacion' => 'required_if:estado,rechazado|nullable|string|max:2000']);
        $s = DB::transaction(function () use ($r, $application, $document, $d) {
            $s = $this->intake->resolve('ingreso-solicitud', $application, Solicitud::lockForUpdate());
            abort_unless(in_array($s->estado, EnrollmentIntake::REVIEWABLE, true), 422, __('La solicitud no está en revisión.'));
            $doc = $this->intake->resolve('ingreso-documento', $document, Documento::where('solicitud_id', $s->id));
            abort_if($s->documentos()->where('requisito', $doc->requisito)->where('version', '>', $doc->version)->exists(), 422, __('Revisa la versión más reciente.'));
            if ($doc->estado === $d['estado'] && $doc->observacion === ($d['observacion'] ?? null)) {
                return $s;
            }
            $doc->update([...$d, 'revisor_id' => $r->user()->id]);
            $s->update(['estado' => $d['estado'] === 'rechazado' ? 'correcciones' : ($s->estado === 'correcciones' ? 'correcciones' : 'revision')]);
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
        $d = $r->validate(['estado' => ['required', Rule::in(['aprobada', 'rechazada', 'espera', 'correcciones'])],
            'grado_token' => 'required_if:estado,aprobada|nullable|string|size:24', 'observacion' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->intake->present($this->intake->decide($this->application($application), $r->user(), $d))]);
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

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function studentStatus(Request $r)
    {
        $s = Solicitud::where('estudiante_id', $r->user()->id)->where('estado', 'aprobada')->exists();

        return response()->json(['grupo_pendiente' => $s]);
    }
}
