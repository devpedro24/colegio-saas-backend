<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\TenantOnboarding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()->must_change_password
            || TenantOnboarding::institutionRequired($request->user())) {
            return response()->json([
                'message' => 'Completa la configuración inicial antes de continuar.',
                'onboarding_required' => true,
            ], 423);
        }

        return $next($request);
    }
}
