<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditPublicPresenter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function colegios(): JsonResponse
    {
        return response()->json(['data' => Tenant::query()->orderBy('name')->get(['slug', 'name', 'tipo'])]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'colegio_slug' => ['nullable', 'string', 'exists:tenants,slug'],
            // Legacy callers can still filter by ID during the browser migration.
            'tenant_id' => ['nullable', 'string', 'exists:tenants,id'],
            'actor' => ['nullable', 'string', 'max:255'],
            'rol' => ['nullable', 'string', 'max:100'],
            'accion' => ['nullable', 'string', 'max:80'],
            'recurso' => ['nullable', 'string', 'max:100'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', ...($request->filled('desde') ? ['after_or_equal:desde'] : [])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $read = function (?Tenant $school) use ($filters): array {
            $query = $school ? AuditLog::query() : PlatformAuditLog::query();
            foreach (['accion', 'recurso', 'rol' => 'actor_rol'] as $key => $column) {
                $key = is_int($key) ? $column : $key;
                if (! empty($filters[$key])) {
                    $query->where($column, $filters[$key]);
                }
            }
            if (! empty($filters['actor'])) {
                $query->where('actor_email', 'like', '%'.addcslashes($filters['actor'], '%_\\').'%');
            }
            if (! empty($filters['desde'])) {
                $query->where('created_at', '>=', $filters['desde'].' 00:00:00');
            }
            if (! empty($filters['hasta'])) {
                $query->where('created_at', '<', Carbon::parse($filters['hasta'])->addDay()->toDateString());
            }

            $result = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 25)->toArray();
            $scope = $school ? (string) $school->id : 'platform';
            $result['data'] = array_map(
                fn (array $entry) => AuditPublicPresenter::entry($entry, $scope),
                $result['data'],
            );

            return $result;
        };
        $school = ! empty($filters['colegio_slug'])
            ? Tenant::where('slug', $filters['colegio_slug'])->firstOrFail()
            : (! empty($filters['tenant_id']) ? Tenant::findOrFail($filters['tenant_id']) : null);
        $result = $school ? $school->run(fn () => $read($school)) : $read(null);
        AuditLogger::platform($request->user(), 'READ', 'auditoria', null, null, $filters, null, $school?->id);

        return response()->json($result);
    }
}
