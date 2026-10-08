<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth\BrowserAuthCookies;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Resolves browser cookies before tenancy and Sanctum authenticate the request. */
final class ResolveBrowserSession
{
    private const PLATFORM_PATHS = [
        'api/platform', 'api/colegios', 'api/plans', 'api/planes', 'api/rbac',
        'api/login', 'api/logout', 'api/me', 'api/user', 'api/account',
        'api/mfa', 'api/forgot-password', 'api/reset-password',
        'api/broadcasting/auth',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null && ! $this->sameOrigin($request, $origin)) {
            return response()->json(['message' => 'Origen de solicitud no autorizado.'], 403);
        }
        // Browsers send Fetch Metadata even when an intermediary strips Origin.
        // A cross-site form must not be able to replace the victim's login cookie.
        $fetchSite = strtolower((string) $request->headers->get('Sec-Fetch-Site', ''));
        if (! $request->isMethodSafe() && $fetchSite !== '' && $fetchSite !== 'same-origin') {
            return response()->json(['message' => 'Origen de solicitud no autorizado.'], 403);
        }

        // Clients explicitly using Bearer keep the legacy API contract for the transition.
        if ($request->bearerToken() !== null || $this->publicCredentialRoute($request)) {
            return $next($request);
        }

        $central = in_array($request->getHost(), config('tenancy.central_domains'), true);
        $impersonating = $central && ! $this->platformPath($request);
        $name = $central
            ? ($impersonating ? BrowserAuthCookies::IMPERSONATION : BrowserAuthCookies::PLATFORM)
            : BrowserAuthCookies::TENANT;
        $context = BrowserAuthCookies::read($request, $name);
        if ($context === null) {
            return $next($request);
        }

        if ($impersonating) {
            $platform = BrowserAuthCookies::read($request, BrowserAuthCookies::PLATFORM);
            if ($platform === null || ! is_string($context['tenant_id'] ?? null)
                || ! is_string($context['platform_token_hash'] ?? null)
                || ! hash_equals($context['platform_token_hash'], hash('sha256', $platform['token']))
                || ! hash_equals($platform['csrf'], $context['csrf'])) {
                return response()->json(['message' => 'La sesión de soporte ya no está activa.'], 401);
            }
            $request->headers->set('X-Tenant', $context['tenant_id']);
            $request->attributes->set('browser_platform', $platform);
        }

        if (! $request->isMethodSafe()) {
            if ($origin === null || ! hash_equals($context['csrf'], (string) $request->headers->get('X-CSRF-Token', ''))) {
                return response()->json(['message' => 'La verificación de seguridad de la solicitud falló.'], 419);
            }
        }

        $request->headers->set('Authorization', 'Bearer '.$context['token']);
        $request->attributes->set('browser_auth', $context);

        return $next($request);
    }

    private function publicCredentialRoute(Request $request): bool
    {
        return $request->is('api/ingreso-publico/*', 'api/login', 'api/forgot-password', 'api/reset-password',
            'api/account/google/callback');
    }

    private function platformPath(Request $request): bool
    {
        foreach (self::PLATFORM_PATHS as $path) {
            if ($request->is($path, $path.'/*')) {
                return true;
            }
        }

        return false;
    }

    private function sameOrigin(Request $request, string $origin): bool
    {
        $expected = strtolower($request->getSchemeAndHttpHost());

        return hash_equals($expected, strtolower(rtrim($origin, '/')));
    }
}
