<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Impersonation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMfaReady
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && tenancy()->initialized && $user->email === User::PLATFORM_SUPERADMIN_EMAIL) {
            $session = Impersonation::where('tenant_id', tenant()->getKey())
                ->where('token_id', $user->currentAccessToken()?->id)->whereNull('ended_at')
                ->where('expires_at', '>', now())->first();
            $actor = $session ? User::on(config('tenancy.database.central_connection'))
                ->find($session->superadmin_id) : null;
            if (! $actor || $actor->status !== 'active' || $actor->role !== 'superadmin') {
                return response()->json(['message' => 'La sesión de soporte ya no está activa. Vuelve a entrar al colegio.'], 403);
            }
        }
        $token = $user?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->name === 'mfa-enrollment') {
            // Las sesiones emitidas por la política anterior también recuperan acceso.
            $token->forceFill(['name' => tenancy()->initialized ? 'web' : 'platform',
                'abilities' => ['*'], 'expires_at' => now()->addHours(8)])->save();
        }

        return $next($request);
    }
}
