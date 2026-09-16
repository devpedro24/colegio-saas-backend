<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Impersonation\ImpersonationAccess;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureImpersonationToken
{
    public function __construct(private readonly ImpersonationAccess $access) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $user = $request->user();

        // En rutas tenant, los tokens ordinarios deben seguir su flujo normal.
        // Si el bearer pertenece a una suplantacion, en cambio, su sesion
        // central debe continuar vigente incluso cuando llega por subdominio.
        if ($mode === 'when-present'
            && $user instanceof User
            && ! $this->access->isImpersonationToken($user->currentAccessToken())
            && ! $user->isImpersonationShadow()) {
            return $next($request);
        }

        if (! $user instanceof User || $this->access->sessionFor($user) === null) {
            return new JsonResponse(['message' => 'La sesion de suplantacion no es valida o ya vencio.'], 401);
        }

        return $next($request);
    }
}
