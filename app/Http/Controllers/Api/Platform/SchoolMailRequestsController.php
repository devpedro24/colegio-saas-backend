<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\CorreoConfiguracion;
use App\Models\SchoolMailChangeRequest;
use App\Models\Tenant;
use App\Services\SchoolMailApproval;
use App\Support\Audit\AuditLogger;
use App\Support\Realtime\RealtimeChanges;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchoolMailRequestsController extends Controller
{
    private function authorizePlatform(Request $r): void
    {
        abort_unless(! tenancy()->initialized && $r->user()->role === 'superadmin', 403);
    }

    private function settings(?Tenant $school): ?CorreoConfiguracion
    {
        // Read only approval metadata; never bring credentials into the platform presenter.
        if (! $school || in_array($school->status, ['provisioning', 'deleted', 'finalized'], true)) {
            return null;
        }

        // A platform inbox must not switch global cache/filesystem/tenant context.
        // Use an isolated, short-lived connection for this metadata-only SELECT.
        $name = 'school-mail-inspection-'.$school->getKey();
        try {
            $connection = DB::build([...$school->database()->connection(), 'name' => $name]);
            $row = $connection->table('correo_configuracion')->where('key', 'gmail')
                ->first(['key', 'revision', 'ultima_autorizacion']);

            return $row ? new CorreoConfiguracion((array) $row) : null;
        } finally {
            DB::purge($name);
        }
    }

    private function entry(SchoolMailChangeRequest $row, ?Tenant $school, SchoolMailApproval $approval, ?CorreoConfiguracion $settings, bool $available = true): array
    {
        $data = $approval->present($row, $settings);
        if (! $available) {
            $data['estado'] = $row->estado;
        }

        return [...$data, 'disponible' => $available, 'solicitante' => $row->requester_name,
            'colegio' => ['slug' => $school?->slug, 'nombre' => $school?->name ?? __('Colegio no disponible')]];
    }

    public function index(Request $r, SchoolMailApproval $approval)
    {
        $this->authorizePlatform($r);
        $d = $r->validate(['estado' => 'nullable|in:pendiente,todas', 'page' => 'nullable|integer|min:1']);
        $page = SchoolMailChangeRequest::when(($d['estado'] ?? 'pendiente') === 'pendiente', fn ($q) => $q->where('estado', 'pendiente'))
            ->latest('id')->paginate(20);
        $schools = Tenant::whereIn('id', $page->getCollection()->pluck('tenant_id')->unique())->get()->keyBy('id');
        $settings = $schools->map(function ($school) {
            try {
                return ['value' => $this->settings($school), 'available' => true];
            } catch (\Throwable) {
                return ['value' => null, 'available' => false];
            }
        });

        return response()->json(['data' => $page->getCollection()->map(fn ($row) => $this->entry($row, $schools->get($row->tenant_id), $approval,
            $settings->get($row->tenant_id)['value'] ?? null, $settings->get($row->tenant_id)['available'] ?? true)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => 20]])
            ->header('Cache-Control', 'no-store, private');
    }

    public function summary(Request $r)
    {
        $this->authorizePlatform($r);

        return response()->json(['pendientes' => SchoolMailChangeRequest::where('estado', 'pendiente')->count()])->header('Cache-Control', 'no-store, private');
    }

    public function resolve(Request $r, string $token, SchoolMailApproval $approval)
    {
        $this->authorizePlatform($r);
        abort_unless(preg_match('/^[A-Za-z0-9_-]{24}$/D', $token), 404);
        $d = $r->validate(['decision' => 'required|in:aprobar,rechazar', 'observacion' => 'nullable|required_if:decision,rechazar|string|min:10|max:1000']);
        $row = SchoolMailChangeRequest::where('url_token', $token)->firstOrFail();
        $school = Tenant::findOrFail($row->tenant_id);
        try {
            $settings = $this->settings($school);
        } catch (\Throwable) {
            abort(503, __('No se pudo consultar este colegio. Intenta cuando vuelva a estar disponible.'));
        }
        $row->getConnection()->transaction(function () use ($row, $settings, $r, $d, $approval) {
            $locked = SchoolMailChangeRequest::whereKey($row->id)->lockForUpdate()->firstOrFail();
            abort_unless($approval->state($locked, $settings) === 'pendiente', 409, __('Esta solicitud ya no está pendiente.'));
            $locked->update(['estado' => $d['decision'] === 'aprobar' ? 'aprobada' : 'rechazada',
                'reviewer_id' => (string) $r->user()->getKey(), 'reviewed_at' => now(),
                'expires_at' => $d['decision'] === 'aprobar' ? now()->addDay() : null, 'observacion' => $d['observacion'] ?? null]);
            AuditLogger::platform($r->user(), 'UPDATE', 'correo-solicitud', $locked->url_token, ['estado' => 'pendiente'],
                ['estado' => $locked->estado], null, $locked->tenant_id);
        });
        app(RealtimeChanges::class)->record($school->id, 'school-mail-requests');

        return response()->json(['data' => $this->entry($row->fresh(), $school, $approval, $settings)]);
    }
}
