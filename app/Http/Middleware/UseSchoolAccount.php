<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Services\SedeAccess;
use App\Support\Account\AccountPresenter;
use App\Support\Auth\BrowserAuthCookies;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Shared accounts update their credentials at the authoritative parent school. */
final class UseSchoolAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $local = $request->user();
        if (! tenant()?->parent_id || ! $local?->school_user_id
            || ! $request->is('api/account', 'api/account/*', 'api/mfa/*')) {
            return $next($request);
        }
        $resolver = $request->getUserResolver();
        $source = app(SedeAccess::class)->source($local->email, (int) $local->school_user_id);
        abort_unless($source, 401);
        if ($request->is('api/account/email') && is_string($request->input('email'))
            && User::withTrashed()->where('email', mb_strtolower(trim($request->input('email'))))
                ->whereKeyNot($local->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'El correo ya está en uso en esta sede.']);
        }
        $school = Tenant::findOrFail(tenant()->parent_id);
        try {
            [$response, $updated] = $school->run(function () use ($request, $next, $source) {
                $request->setUserResolver(fn () => $source);
                $response = $next($request);
                $updated = User::whereKey($source->id)->with('roles', 'permissions')->first();

                return [$response, $updated];
            });
        } finally {
            $request->setUserResolver($resolver);
        }
        if ($response->isSuccessful() && $updated && $updated->status === 'active') {
            $local = app(SedeAccess::class)->localAccount($updated);
            if (! $request->isMethodSafe()) {
                $expires = now()->addHours(8);
                $token = $local->createToken('web', ['*'], $expires)->plainTextToken;
                BrowserAuthCookies::setSession($response, $request, BrowserAuthCookies::TENANT,
                    $token, $expires->timestamp, BrowserAuthCookies::newCsrf());
            }
            if ($response instanceof JsonResponse) {
                $body = $response->getData(true);
                if (isset($body['user'])) {
                    $body['user'] = [...AccountPresenter::user($local), 'mfa_enabled' => $updated->hasTwoFactorEnabled()];
                    $response->setData($body);
                }
            }
        }

        return $response;
    }
}
