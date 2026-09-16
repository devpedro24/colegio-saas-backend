<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Mfa\MfaPolicy;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMfaConfigured
{
    public function __construct(private readonly MfaPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->policy->isRequired($user) && ! $user->hasTwoFactorEnabled()) {
            return new JsonResponse([
                'message' => 'Debes configurar la verificacion en dos pasos para continuar.',
                'mfa_setup_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
