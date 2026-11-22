<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\LoginAppearanceService;
use App\Support\Audit\AuditLogger;
use App\Support\Storage\StorageException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class LoginAppearanceController extends Controller
{
    public function __construct(private readonly LoginAppearanceService $appearance) {}

    public function publicShow(): JsonResponse
    {
        return response()->json($this->appearance->payload(tenant(), '/api/branding/login/image'))
            ->header('Cache-Control', 'no-store');
    }

    public function publicImage(Request $request): BinaryFileResponse
    {
        return $this->appearance->image($request, tenant(), true);
    }

    public function show(Request $request, ?string $slug = null): JsonResponse
    {
        $school = $this->authorizeScope($request, $slug);

        return response()->json($this->appearance->payload($school, '/'.$request->path().'/image'))
            ->header('Cache-Control', 'no-store');
    }

    public function schools(Request $request): JsonResponse
    {
        $this->authorizeScope($request, null);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1']]);
        $query = Tenant::query()->where('status', '!=', Tenant::STATUS_DELETED);
        $search = mb_strtolower(trim($data['q'] ?? ''));
        if ($search !== '') {
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                ->orWhereRaw('LOWER(slug) LIKE ?', ['%'.$search.'%']));
        }
        $page = $query->orderBy('name')->orderBy('slug')->simplePaginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Tenant $school) => [
                'slug' => $school->slug, 'name' => $school->name,
            ])->values(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function image(Request $request, ?string $slug = null): BinaryFileResponse
    {
        return $this->appearance->image($request, $this->authorizeScope($request, $slug));
    }

    public function update(Request $request, ?string $slug = null): JsonResponse
    {
        $school = $this->authorizeScope($request, $slug);
        $data = $request->validate([
            'version' => ['required', 'string', 'max:32'],
            'image' => [$request->isMethod('DELETE') ? 'prohibited' : 'required', 'file',
                'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);
        $previous = $this->appearance->payload($school, '/api/branding/login/image');
        try {
            $this->appearance->change($school, $data['version'], $request->file('image'), $request->user()->email);
        } catch (StorageException $error) {
            throw ValidationException::withMessages(['image' => [$error->getMessage()]]);
        }
        $new = $this->appearance->payload($school, '/api/branding/login/image');
        if (tenancy()->initialized) {
            AuditLogger::tenant($request->user(), 'UPDATE', 'config.login_appearance', null, $previous, $new);
        } else {
            AuditLogger::platform($request->user(), 'UPDATE', 'config.login_appearance', null,
                $previous, $new, null, $school?->getKey());
        }

        return $this->show($request, $slug);
    }

    private function authorizeScope(Request $request, ?string $slug): ?Tenant
    {
        $user = $request->user();
        if ($slug !== null || $request->is('api/platform/*')) {
            abort_unless(! tenancy()->initialized && $user?->role === 'superadmin', 403);

            return $slug === null ? null : Tenant::where('slug', $slug)->firstOrFail();
        }
        abort_unless(tenant() && $user && ($user->esSuperadminPlataforma()
            || ($user->hasRole('rector') && $user->can('academico.configurar'))), 403);
        abort_if($user->must_change_password, 423, 'Cambia primero la contraseña temporal.');

        return tenant();
    }
}
