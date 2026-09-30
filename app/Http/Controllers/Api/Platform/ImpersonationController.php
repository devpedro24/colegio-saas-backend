<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Impersonation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\BrowserAuthCookies;
use App\Support\Realtime\TenantChannelName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * SUPLANTACION / cambio de contexto del superadministrador (RN-RT-402, RN-LA-002).
 *
 * Rutas CENTRALES (dominio de plataforma), protegidas por auth:sanctum + platform.
 * Al entrar a un colegio se emite un token temporal (contexto tenant) sobre un
 * usuario sombra 'rector' del colegio; con ese token + el header 'X-Tenant' el
 * superadmin alcanza los MISMOS controladores academicos del colegio. Toda accion
 * queda auditada por partida doble (plataforma + colegio, con `impersonated_by`).
 */
class ImpersonationController extends Controller
{
    /** Email/identidad fijos del usuario sombra dentro de cada colegio. */
    private const SHADOW_EMAIL = User::PLATFORM_SUPERADMIN_EMAIL;

    private const SHADOW_NAME = 'Superadministrador (plataforma)';

    private const SHADOW_ROLE = 'rector';

    private const TOKEN_NAME = 'impersonation';

    /** Duracion de la ventana de suplantacion. */
    private const TTL_HOURS = 2;

    /**
     * Entra a administrar un colegio: crea/renueva la sesion de suplantacion,
     * asegura el usuario sombra y emite su token temporal.
     */
    public function impersonar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'colegio_slug' => ['required_without:colegio_id', 'string', 'exists:tenants,slug'],
            'colegio_id' => ['required_without:colegio_slug', 'string', 'exists:tenants,id'],
        ]);

        $superadmin = $request->user();
        $tenant = isset($data['colegio_slug'])
            ? Tenant::where('slug', $data['colegio_slug'])->firstOrFail()
            : Tenant::findOrFail($data['colegio_id']);

        // Ventana de validez en UTC (el token y la fila comparten expiracion).
        $expiresAt = Carbon::now('UTC')->addHours(self::TTL_HOURS);

        // Sesion de suplantacion (central): una fila viva por (superadmin, colegio).
        $session = Impersonation::updateOrCreate(
            [
                'superadmin_id' => $superadmin->id,
                'tenant_id' => (string) $tenant->id,
            ],
            [
                'superadmin_email' => $superadmin->email,
                'started_at' => Carbon::now('UTC'),
                'expires_at' => $expiresAt,
                'ended_at' => null,
            ],
        );

        // Dentro del colegio: asegura el usuario sombra y emite el token.
        $plainToken = $tenant->run(function () use ($superadmin, $tenant, $expiresAt, $session) {
            $shadow = User::firstOrCreate(
                ['email' => self::SHADOW_EMAIL],
                [
                    'name' => self::SHADOW_NAME,
                    'password' => Hash::make(Str::password(32)),
                    'role' => self::SHADOW_ROLE,
                    'status' => 'active',
                    'must_change_password' => false,
                ],
            );

            // Garantiza estado/rol correctos aunque la fila ya existiera.
            if ($shadow->role !== self::SHADOW_ROLE || $shadow->status !== 'active') {
                $shadow->forceFill([
                    'role' => self::SHADOW_ROLE,
                    'status' => 'active',
                ])->save();
            }

            if (! $shadow->hasRole(self::SHADOW_ROLE)) {
                $shadow->assignRole(self::SHADOW_ROLE);
            }

            // Un solo token de suplantacion vivo por sesion.
            $shadow->tokens()->where('name', self::TOKEN_NAME)->whereKey($session->token_id)->delete();

            $issued = $shadow->createToken(self::TOKEN_NAME, ['*'], $expiresAt);
            $session->update(['token_id' => $issued->accessToken->id]);
            $token = $issued->plainTextToken;

            // Auditoria DENTRO del colegio (con el superadmin como impersonated_by).
            AuditLogger::tenant(
                $superadmin,
                'IMPERSONATE_START',
                'sesion',
                (string) $tenant->id,
                null,
                null,
                null,
                $superadmin->email,
            );

            return $token;
        });

        // Auditoria de PLATAFORMA (central).
        AuditLogger::platform(
            $superadmin,
            'IMPERSONATE_START',
            'colegio',
            (string) $tenant->id,
            null,
            ['slug' => $tenant->slug],
            null,
            (string) $tenant->id,
        );

        $platformToken = $request->bearerToken();
        abort_unless(is_string($platformToken) && $platformToken !== '', 401);
        $csrf = BrowserAuthCookies::csrf($request) ?? BrowserAuthCookies::newCsrf();
        $response = response()->json([
            'data' => [
                'colegio' => [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'channel_token' => TenantChannelName::tokenForId((string) $tenant->id),
                ],
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
        if (BrowserAuthCookies::csrf($request) === null) {
            $platformExpiry = $superadmin->currentAccessToken()?->expires_at?->timestamp
                ?? now()->addHours(8)->timestamp;
            BrowserAuthCookies::setSession($response, $request, BrowserAuthCookies::PLATFORM,
                $platformToken, $platformExpiry, $csrf);
        }
        BrowserAuthCookies::setImpersonation($response, $request, $plainToken,
            (string) $tenant->id, $platformToken, $expiresAt->timestamp, $csrf);

        return $response;
    }

    /** Reconstitutes the platform user's selected school after a browser reload. */
    public function estado(Request $request): JsonResponse
    {
        $cookie = BrowserAuthCookies::read($request, BrowserAuthCookies::IMPERSONATION);
        $platformToken = $request->bearerToken();
        $session = null;
        if ($cookie && is_string($platformToken) && is_string($cookie['tenant_id'] ?? null)
            && is_string($cookie['platform_token_hash'] ?? null)
            && hash_equals($cookie['platform_token_hash'], hash('sha256', $platformToken))) {
            $session = Impersonation::where('superadmin_id', $request->user()->id)
                ->where('tenant_id', $cookie['tenant_id'])
                ->where('token_id', (int) explode('|', $cookie['token'], 2)[0])
                ->whereNull('ended_at')->where('expires_at', '>', now())->first();
        }
        $tenant = $session ? Tenant::find($session->tenant_id) : null;
        $response = response()->json(['data' => ['colegio' => $tenant ? [
            'name' => $tenant->name, 'slug' => $tenant->slug,
            'channel_token' => TenantChannelName::tokenForId((string) $tenant->id),
        ] : null]]);
        if (! $tenant && $cookie) {
            BrowserAuthCookies::clearImpersonation($response, $request);
        }

        return $response;
    }

    /**
     * Vuelve a Plataforma: cierra la sesion de suplantacion y revoca el token
     * temporal del usuario sombra en el colegio.
     */
    public function salir(Request $request): JsonResponse
    {
        $data = $request->validate([
            'colegio_id' => ['nullable', 'string', 'exists:tenants,id'],
            'colegio_slug' => ['nullable', 'string', 'exists:tenants,slug'],
        ]);

        $superadmin = $request->user();
        $cookie = BrowserAuthCookies::read($request, BrowserAuthCookies::IMPERSONATION);
        $cookieTenant = $cookie && is_string($cookie['tenant_id'] ?? null)
            && is_string($cookie['platform_token_hash'] ?? null)
            && hash_equals($cookie['platform_token_hash'], hash('sha256', (string) $request->bearerToken()))
            ? $cookie['tenant_id'] : null;
        abort_unless($cookieTenant || isset($data['colegio_slug']) || isset($data['colegio_id']), 422,
            'No hay un colegio en administración.');
        $tenant = $cookieTenant ? Tenant::findOrFail($cookieTenant)
            : (isset($data['colegio_slug']) ? Tenant::where('slug', $data['colegio_slug'])->firstOrFail()
                : Tenant::findOrFail($data['colegio_id']));

        // Cierra la(s) sesion(es) viva(s) de este superadmin sobre el colegio.
        $sessions = Impersonation::query()
            ->where('superadmin_id', $superadmin->id)
            ->where('tenant_id', (string) $tenant->id)
            ->whereNull('ended_at');
        $tokenIds = (clone $sessions)->pluck('token_id')->filter()->all();
        $sessions->update(['ended_at' => Carbon::now('UTC')]);

        // Revoca únicamente los tokens de este administrador. Conserva la identidad histórica.
        $tenant->run(function () use ($superadmin, $tenant, $tokenIds) {
            $shadow = User::where('email', self::SHADOW_EMAIL)->first();

            if ($shadow !== null) {
                $shadow->tokens()->where('name', self::TOKEN_NAME)->whereIn('id', $tokenIds)->delete();
                // Conservar la identidad: eventos y evidencia histórica la referencian.
            }

            AuditLogger::tenant(
                $superadmin,
                'IMPERSONATE_STOP',
                'sesion',
                (string) $tenant->id,
                null,
                null,
                null,
                $superadmin->email,
            );
        });

        AuditLogger::platform(
            $superadmin,
            'IMPERSONATE_STOP',
            'colegio',
            (string) $tenant->id,
            null,
            ['slug' => $tenant->slug],
            null,
            (string) $tenant->id,
        );

        $response = response()->json([
            'data' => ['ended' => true],
        ]);
        if ($cookieTenant !== null) {
            BrowserAuthCookies::clearImpersonation($response, $request);
        }

        return $response;
    }
}
