<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Impersonation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Account\AccountPresenter;
use App\Support\Audit\AuditLogger;
use App\Support\PasswordPolicy;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ajustes de cuenta del usuario autenticado (perfil, email, contraseña,
 * desactivación y vinculación de cuenta de Google). Comparte logica para el
 * dominio de PLATAFORMA (BD central) y el de COLEGIO (BD del tenant): en ambos
 * casos opera sobre $request->user() de la conexion activa.
 *
 * Google (anclar cuenta): OAuth2 estandar sin dependencias extra. El paso 1
 * devuelve una URL de autorizacion con un state de un solo uso, vinculado al
 * navegador que inició la conexión mediante una cookie HttpOnly temporal.
 */
class AccountController extends Controller
{
    /** Duracion del state OAuth (minutos). */
    private const STATE_TTL_MINUTES = 15;

    private const STATE_COOKIE = 'colegio_google_link_state';

    /* ------------------------------------------------------------------ */
    /* Perfil */
    /* ------------------------------------------------------------------ */

    public function index(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'roles' => ['prohibited'], 'permissions' => ['prohibited'],
            'role' => ['prohibited'], 'tenant_id' => ['prohibited'], 'plan' => ['prohibited'],
        ]);

        $user = $request->user();
        abort_if(! AccountPresenter::capabilities($user)['edit_name'] && trim($data['name']) !== $user->name,
            403, 'El colegio administra tu nombre académico.');
        $user->update([
            'name' => trim($data['name']),
            'phone' => trim((string) ($data['phone'] ?? '')),
        ]);

        $this->audit($user, 'PROFILE_UPDATE', 'account', (string) $user->id);

        return response()->json([
            'message' => 'Perfil actualizado.',
            'user' => $this->userPayload($user),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Email y contrasena */
    /* ------------------------------------------------------------------ */

    public function changeEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['La contraseña es incorrecta.'],
            ]);
        }

        abort_unless(AccountPresenter::capabilities($user)['edit_email'], 403, 'Solicita el cambio de correo al colegio.');

        $newEmail = mb_strtolower(trim($data['email']));

        if ($newEmail === mb_strtolower($user->email)) {
            throw ValidationException::withMessages([
                'email' => ['El correo ya es el actual.'],
            ]);
        }

        $exists = User::where('email', $newEmail)->where('id', '!=', $user->id)->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'email' => ['El correo ya está en uso.'],
            ]);
        }

        $user->update([
            'email' => $newEmail,
            'email_verified_at' => null,
        ]);

        $this->audit($user, 'EMAIL_UPDATE', 'account', (string) $user->id);

        return response()->json([
            'message' => 'Correo actualizado.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', PasswordPolicy::rule()],
            'new_password_confirmation' => ['required', 'same:new_password'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual es incorrecta.'],
            ]);
        }

        $user->update([
            'password' => $data['new_password'],
            'must_change_password' => false,
            ...(tenancy()->initialized ? ['temporary_password' => null, 'temporary_password_expires_at' => null] : []),
        ]);

        if (tenancy()->initialized && $user->hasRole('rector')) {
            tenant()->update(['rector_temporary_password' => null]);
        }

        // Se mantiene la sesion actual; se invalida el resto por si hubo filtracion.
        $user->tokens()
            ->where('id', '!=', $user->currentAccessToken()?->id ?? 0)
            ->delete();

        if (! tenancy()->initialized) {
            $this->endPlatformImpersonations($user);
        }

        $this->audit($user, 'PASSWORD_UPDATE', 'account', (string) $user->id);

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    private function endPlatformImpersonations(User $user): void
    {
        $sessions = Impersonation::where('superadmin_id', $user->id)
            ->whereNull('ended_at')->get(['id', 'tenant_id', 'token_id']);
        if ($sessions->isEmpty()) {
            return;
        }

        // La fila central se cierra primero: EnsureMfaReady rechaza el token sombra
        // incluso si una BD escolar no está disponible para la revocación física.
        Impersonation::whereKey($sessions->modelKeys())->update(['ended_at' => now()]);
        foreach ($sessions as $session) {
            try {
                $school = Tenant::find($session->tenant_id);
                $school?->run(function () use ($session): void {
                    $shadow = User::where('email', User::PLATFORM_SUPERADMIN_EMAIL)->first();
                    $shadow?->tokens()->where('name', 'impersonation')
                        ->whereKey($session->token_id)->delete();
                });
            } catch (Throwable $error) {
                Log::warning('No se pudo revocar físicamente un token sombra cerrado',
                    ['tenant' => $session->tenant_id, 'error' => $error->getMessage()]);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Desactivar cuenta */
    /* ------------------------------------------------------------------ */

    public function deactivate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['La contraseña es incorrecta.'],
            ]);
        }

        // D-USER-FSM: active -> inactive (la cuenta deja de iniciar sesion).
        $user->transitionTo(User::STATUS_INACTIVE);
        $user->tokens()->delete();

        $this->audit($user, 'ACCOUNT_DEACTIVATE', 'account', (string) $user->id);

        return response()->json(['message' => 'Cuenta desactivada.']);
    }

    /* ------------------------------------------------------------------ */
    /* Google (anclar cuenta) */
    /* ------------------------------------------------------------------ */

    public function googleConnect(Request $request): JsonResponse
    {
        $clientId = config('services.google.client_id');

        if (! $clientId) {
            return response()->json([
                'message' => 'La vinculación con Google todavía no está disponible.',
            ], 422);
        }

        $user = $request->user();
        abort_unless(AccountPresenter::capabilities($user)['google_link'], 403);

        $nonce = Str::random(40);
        $state = $this->buildOAuthState($user, $nonce);
        Cache::put($this->oauthStateCacheKey($nonce), true, now()->addMinutes(self::STATE_TTL_MINUTES));

        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $this->googleCallbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'online',
            'prompt' => 'select_account',
            'state' => $state,
        ]);

        return response()->json(['url' => $authUrl])->cookie(
            self::STATE_COOKIE, $nonce, self::STATE_TTL_MINUTES, '/', null,
            app()->environment('production') || $request->isSecure(), true, false, 'lax',
        );
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $frontUrl = config('app.frontend_url');

        if (empty($request->query('code')) || empty($request->query('state'))) {
            return redirect()->away($frontUrl.'/account/settings?google=error');
        }

        $payload = $this->readOAuthState((string) $request->query('state'));

        if ($payload === null || ! hash_equals($payload['nonce'], (string) $request->cookie(self::STATE_COOKIE))
            || $payload['tenant'] !== (string) (tenant()?->getTenantKey() ?? 'platform')
            || ! $this->consumeOAuthState($payload['nonce'])) {
            return redirect()->away($frontUrl.'/account/settings?google=error');
        }

        $user = User::find($payload['uid']);

        if ($user === null) {
            return redirect()->away($frontUrl.'/account/settings?google=error');
        }

        try {
            $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'code' => $request->query('code'),
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => $this->googleCallbackUrl(),
                'grant_type' => 'authorization_code',
            ])->json();

            if (empty($token['access_token'])) {
                return redirect()->away($frontUrl.'/account/settings?google=error');
            }

            $info = Http::withToken($token['access_token'])
                ->get('https://www.googleapis.com/oauth2/v3/userinfo')
                ->json();

            if (empty($info['sub'])) {
                return redirect()->away($frontUrl.'/account/settings?google=error');
            }

            $user->update([
                'google_id' => (string) $info['sub'],
                'google_email' => mb_strtolower((string) ($info['email'] ?? '')),
                'google_linked_at' => now(),
            ]);

            $this->audit($user, 'GOOGLE_LINK', 'account', (string) $user->id);

            return redirect()->away($frontUrl.'/account/settings?google=linked');
        } catch (Throwable) {
            return redirect()->away($frontUrl.'/account/settings?google=error');
        }
    }

    public function googleUnlink(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'google_id' => null,
            'google_email' => null,
            'google_linked_at' => null,
        ]);

        $this->audit($user, 'GOOGLE_UNLINK', 'account', (string) $user->id);

        return response()->json([
            'message' => 'Cuenta de Google desvinculada.',
            'user' => $this->userPayload($user),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /** El state queda ligado al navegador y al contexto del colegio. */
    private function buildOAuthState(User $user, string $nonce): string
    {
        $value = json_encode([
            'uid' => (string) $user->id,
            'exp' => now()->addMinutes(self::STATE_TTL_MINUTES)->timestamp,
            'tenant' => (string) (tenant()?->getTenantKey() ?? 'platform'),
            'nonce' => $nonce,
        ]);

        return Crypt::encryptString((string) $value);
    }

    /** @return array{uid: string, exp: int, tenant: string, nonce: string}|null */
    private function readOAuthState(string $state): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true);

            if (! is_array($payload) || empty($payload['uid']) || empty($payload['exp'])
                || empty($payload['nonce']) || empty($payload['tenant'])) {
                return null;
            }

            if ((int) $payload['exp'] < now()->timestamp) {
                return null;
            }

            return [
                'uid' => (string) $payload['uid'],
                'exp' => (int) $payload['exp'],
                'tenant' => (string) $payload['tenant'],
                'nonce' => (string) $payload['nonce'],
            ];
        } catch (DecryptException) {
            return null;
        }
    }

    private function oauthStateCacheKey(string $nonce): string
    {
        return 'google-link-state:'.hash('sha256', $nonce);
    }

    private function consumeOAuthState(string $nonce): bool
    {
        $key = $this->oauthStateCacheKey($nonce);
        $lock = Cache::lock($key.':lock', 5);
        if (! $lock->get()) {
            return false;
        }

        try {
            return (bool) Cache::pull($key);
        } finally {
            $lock->release();
        }
    }

    private function googleCallbackUrl(): string
    {
        return url('/api/account/google/callback');
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return AccountPresenter::user($user);
    }

    private function audit(User $user, string $action, string $resource, string $resourceId): void
    {
        if (tenant() !== null) {
            AuditLogger::tenant($user, $action, $resource, $resourceId);

            return;
        }

        AuditLogger::platform($user, $action, $resource, $resourceId);
    }
}
