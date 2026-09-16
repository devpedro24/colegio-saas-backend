<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de cuenta del usuario autenticado (perfil, email, contraseña,
 * desactivación y vinculación de cuenta de Google). Comparte logica para el
 * dominio de PLATAFORMA (BD central) y el de COLEGIO (BD del tenant): en ambos
 * casos opera sobre $request->user() de la conexion activa.
 *
 * Google (anclar cuenta): OAuth2 estandar sin dependencias extra. El paso 1
 * devuelve una URL de autorizacion firmada; el "state" guarda el id del usuario
 * y vence en 10 minutos (no hay sesion server en una SPA stateless con Sanctum).
 */
class AccountController extends Controller
{
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
            'communications' => ['nullable', 'array'],
        ]);

        $user = $request->user();
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

        $newEmail = mb_strtolower(trim($data['email']));

        if (User::isImpersonationShadowEmail($newEmail)) {
            throw ValidationException::withMessages([
                'email' => ['El correo pertenece a un namespace tecnico reservado.'],
            ]);
        }

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
            'new_password' => ['required', Password::min(8)->letters()->numbers()->symbols()],
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
        ]);

        // Se mantiene la sesion actual; se invalida el resto por si hubo filtracion.
        $user->tokens()
            ->where('id', '!=', $user->currentAccessToken()?->id ?? 0)
            ->delete();

        $this->audit($user, 'PASSWORD_UPDATE', 'account', (string) $user->id);

        return response()->json(['message' => 'Contraseña actualizada.']);
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
                'message' => 'La vinculación con Google no está configurada: agrega GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en el .env del backend (OAuth Client de Google Cloud Console).',
            ], 422);
        }

        $user = $request->user();

        $state = $this->buildOAuthState($request, $user);

        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $this->googleCallbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'online',
            'prompt' => 'select_account',
            'state' => $state,
        ]);

        return response()->json(['url' => $authUrl]);
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $frontUrl = $this->frontendUrlFor($request);

        if (empty($request->query('code')) || empty($request->query('state'))) {
            return redirect()->away($frontUrl.'/account/settings?google=error');
        }

        $payload = $this->readOAuthState($request, (string) $request->query('state'));

        if ($payload === null) {
            return redirect()->away($frontUrl.'/account/settings?google=error');
        }

        $frontUrl = $payload['front'];

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
        } catch (\Throwable) {
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

    /** Estado sellado + nonce server-side: expira y solo puede consumirse una vez. */
    private function buildOAuthState(Request $request, User $user): string
    {
        $ttl = max(1, (int) config('security.oauth.state_ttl_minutes', 15));
        $nonce = (string) Str::uuid();
        $context = $this->oauthContext();
        $value = json_encode([
            'uid' => (string) $user->id,
            'exp' => now()->addMinutes($ttl)->timestamp,
            'nonce' => $nonce,
            'context' => $context,
            'front' => $this->frontendUrlFor($request),
        ]);

        $state = Crypt::encryptString((string) $value);
        $this->oauthCache()->put(
            $this->oauthCacheKey($context, $nonce),
            hash('sha256', $state),
            now()->addMinutes($ttl),
        );

        return $state;
    }

    /** @return array{uid:string, exp:int, front:string}|null */
    private function readOAuthState(Request $request, string $state): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true);

            if (! is_array($payload)
                || empty($payload['uid'])
                || empty($payload['exp'])
                || empty($payload['nonce'])
                || empty($payload['context'])
                || empty($payload['front'])) {
                return null;
            }

            $context = (string) $payload['context'];
            if ((int) $payload['exp'] < now()->timestamp || ! hash_equals($this->oauthContext(), $context)) {
                return null;
            }

            $marker = $this->oauthCache()->pull($this->oauthCacheKey($context, (string) $payload['nonce']));
            if (! is_string($marker) || ! hash_equals($marker, hash('sha256', $state))) {
                return null;
            }

            $front = $this->validateFrontendUrl((string) $payload['front'], $request);
            if ($front === null) {
                return null;
            }

            return [
                'uid' => (string) $payload['uid'],
                'exp' => (int) $payload['exp'],
                'front' => $front,
            ];
        } catch (DecryptException) {
            return null;
        }
    }

    private function googleCallbackUrl(): string
    {
        return url('/api/account/google/callback');
    }

    private function oauthContext(): string
    {
        $tenantId = function_exists('tenant') ? tenant()?->getKey() : null;

        return $tenantId !== null ? 'tenant:'.$tenantId : 'platform';
    }

    private function oauthCacheKey(string $context, string $nonce): string
    {
        return 'oauth:google:state:'.hash('sha256', $context.'|'.$nonce);
    }

    /** Store explicito: no hereda tags/conexion de la BD tenant activa. */
    private function oauthCache(): CacheRepository
    {
        return Cache::store((string) config('security.oauth.cache_store', 'central_database'));
    }

    /** URL del frontend vinculada al host actual, nunca tomada de query params. */
    private function frontendUrlFor(Request $request): string
    {
        $origin = $request->headers->get('Origin');
        if (is_string($origin) && ($valid = $this->validateFrontendUrl($origin, $request)) !== null) {
            return $valid;
        }

        $configured = rtrim((string) config('app.frontend_url'), '/');
        $parts = parse_url($configured);

        if (function_exists('tenant') && tenant() !== null && is_array($parts)) {
            $scheme = in_array($parts['scheme'] ?? null, ['http', 'https'], true)
                ? $parts['scheme']
                : $request->getScheme();
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';

            return $scheme.'://'.$request->getHost().$port;
        }

        return $configured;
    }

    private function validateFrontendUrl(string $url, Request $request): ?string
    {
        $parts = parse_url(rtrim($url, '/'));
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        if ($request->isSecure() && $parts['scheme'] !== 'https') {
            return null;
        }

        $allowedHosts = [$request->getHost()];
        // En tenant el redirect queda atado al mismo subdominio para que un
        // state de un colegio nunca termine en el frontend de otro contexto.
        if (! (function_exists('tenant') && tenant() !== null)) {
            $configuredHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
            if (is_string($configuredHost)) {
                $allowedHosts[] = $configuredHost;
            }
        }

        if (! in_array(Str::lower((string) $parts['host']), array_map('strtolower', $allowedHosts), true)) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'google_email' => $user->google_email,
            'tenant_id' => tenant()?->getKey(),
            'mfa_enabled' => $user->hasTwoFactorEnabled(),
            'roles' => tenant() ? $user->getRoleNames()->values() : [$user->role ?? 'superadmin'],
            'permissions' => tenant() ? $user->getAllPermissions()->pluck('name')->values() : [],
            'is_platform' => tenant() === null,
            'is_superadmin' => $user->esSuperadminPlataforma(),
        ];
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
