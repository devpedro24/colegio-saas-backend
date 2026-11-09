<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Api\Academico\SedeController;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Platform entry points share campus validation, provisioning and presentation. */
final class ColegioSedeController extends Controller
{
    public function index(Request $request, string $slug): JsonResponse
    {
        return $this->execute($request, $slug, 'index');
    }

    public function options(Request $request, string $slug): JsonResponse
    {
        return $this->execute($request, $slug, 'options');
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        return $this->execute($request, $slug, 'store');
    }

    public function update(Request $request, string $slug, string $sedeToken): JsonResponse
    {
        return $this->execute($request, $slug, 'update', $sedeToken);
    }

    public function destroy(Request $request, string $slug, string $sedeToken): JsonResponse
    {
        return $this->execute($request, $slug, 'destroy', $sedeToken);
    }

    private function execute(Request $request, string $slug, string $action, ?string $token = null): JsonResponse
    {
        $school = Tenant::where('slug', $slug)->whereNull('parent_id')->firstOrFail();
        $actor = $request->user();
        $resolver = $request->getUserResolver();
        $request->query->set('opaque', '1');
        $previous = null;
        try {
            // A central actor never becomes a tenant user by matching a numeric ID.
            $request->setUserResolver(fn () => null);
            $response = $school->run(function () use ($request, $action, $token, &$previous) {
                $controller = app(SedeController::class);
                if ($token) {
                    $previous = $controller->show($token)->getData(true)['data'];
                }

                return $token ? $controller->{$action}($request, $token) : $controller->{$action}($request);
            });
        } finally {
            $request->setUserResolver($resolver);
        }
        if (! $request->isMethodSafe() && $response->isSuccessful()) {
            AuditLogger::platform($actor, match ($action) {
                'store' => 'CREATE', 'update' => 'UPDATE', 'destroy' => 'DELETE'
            },
                'colegio.sede', $token, $previous, $response->getData(true)['data'], null, $school->id);
        }

        return $response;
    }
}
