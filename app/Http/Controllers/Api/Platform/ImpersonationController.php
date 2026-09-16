<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImpersonationSessionManager;
use App\Support\Audit\AuditLogger;
use App\Support\Impersonation\ImpersonationAccess;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/** Sesiones auditadas de administracion temporal sobre un tenant. */
final class ImpersonationController extends Controller
{
    private const SHADOW_NAME = 'Superadministrador (plataforma)';

    private const SHADOW_ROLE = 'rector';

    public function impersonar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'colegio_id' => ['required', 'string', 'exists:tenants,id'],
            'motivo' => [
                Rule::requiredIf((bool) config('security.impersonation.reason_required', false)),
                'nullable', 'string', 'max:500',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && mb_strlen(trim((string) $value)) < 10) {
                        $fail('El motivo debe tener al menos 10 caracteres utiles.');
                    }
                },
            ],
            'ticket' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var User $superadmin */
        $superadmin = $request->user();
        $tenant = Tenant::findOrFail($data['colegio_id']);
        $sessionId = (string) Str::uuid();
        $expiresAt = Carbon::now('UTC')->addMinutes(
            max(1, (int) config('security.impersonation.ttl_minutes', 120)),
        );
        $motivo = trim((string) ($data['motivo'] ?? 'Acceso administrativo desde el panel de plataforma.'));
        $ticket = isset($data['ticket']) ? trim((string) $data['ticket']) : null;

        $session = app(ImpersonationSessionManager::class)->start(
            $superadmin,
            $tenant,
            $sessionId,
            $expiresAt,
            $motivo,
            $ticket,
        );

        try {
            $plainToken = $this->inTenant($tenant, function () use (
                $superadmin, $tenant, $session, $expiresAt, $motivo, $ticket,
            ): string {
                return DB::transaction(function () use (
                    $superadmin, $tenant, $session, $expiresAt, $motivo, $ticket,
                ): string {
                    $shadowEmail = User::impersonationShadowEmail($superadmin->id);
                    $candidates = User::withTrashed()
                        ->whereRaw('LOWER(email) = ?', [strtolower($shadowEmail)])
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $shadow = $candidates->first(
                        fn (User $candidate): bool => $candidate->email === $shadowEmail,
                    ) ?? $candidates->first() ?? new User;
                    if ($shadow->exists) {
                        $candidates = $candidates->reject(
                            fn (User $candidate): bool => $candidate->is($shadow),
                        );
                    }

                    // Neutraliza duplicados legacy con variantes de mayusculas.
                    // Ninguna cuenta reservada conserva password, rol o tokens.
                    foreach ($candidates as $duplicate) {
                        $duplicate->tokens()->delete();
                        $duplicate->syncRoles([]);
                        $duplicate->forceFill([
                            'password' => Hash::make(Str::password(32)),
                            'role' => null,
                            'status' => User::STATUS_INACTIVE,
                            'must_change_password' => false,
                        ])->save();
                        if (! $duplicate->trashed()) {
                            $duplicate->delete();
                        }
                    }

                    // Incluso si la cuenta fue precreada por un usuario, rota
                    // siempre el secreto y revoca todos los bearers anteriores.
                    if ($shadow->exists) {
                        $shadow->tokens()->delete();
                    }
                    $shadow->forceFill([
                        'email' => $shadowEmail,
                        'name' => self::SHADOW_NAME.' - '.$superadmin->id,
                        'password' => Hash::make(Str::password(32)),
                        'role' => self::SHADOW_ROLE,
                        'status' => User::STATUS_ACTIVE,
                        'must_change_password' => false,
                        'two_factor_secret' => null,
                        'two_factor_confirmed_at' => null,
                        'two_factor_recovery_codes' => null,
                        'remember_token' => null,
                        'deleted_at' => null,
                    ])->save();
                    $shadow->syncRoles([self::SHADOW_ROLE]);

                    $issuedToken = $shadow->createToken(
                        ImpersonationAccess::tokenName($session->session_id),
                        ['impersonate'],
                        $expiresAt,
                    );

                    // Otra solicitud del mismo superadmin pudo reemplazar esta
                    // sesion mientras se emitia el token en la BD del tenant.
                    // Nunca se devuelve un bearer cuya fuente central ya termino.
                    if ($session->fresh()->ended_at !== null) {
                        $issuedToken->accessToken->delete();

                        abort(409, 'La sesion fue reemplazada por otra solicitud de suplantacion.');
                    }

                    AuditLogger::tenant(
                        $superadmin, 'IMPERSONATE_START', 'sesion', $session->session_id,
                        null,
                        ['tenant_id' => (string) $tenant->id, 'ticket' => $ticket],
                        $motivo,
                        $superadmin->email,
                    );

                    return $issuedToken->plainTextToken;
                });
            });
        } catch (Throwable $e) {
            app(ImpersonationSessionManager::class)->endLatest(
                $superadmin,
                $tenant,
                $session->session_id,
            );
            throw $e;
        }

        AuditLogger::platform(
            $superadmin, 'IMPERSONATE_START', 'sesion', $session->session_id,
            null,
            ['tenant_id' => (string) $tenant->id, 'slug' => $tenant->slug, 'ticket' => $ticket],
            $motivo,
            (string) $tenant->id,
        );

        return response()->json(['data' => [
            'session_id' => $session->session_id,
            'colegio' => [
                'id' => (string) $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'token' => $plainToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ]]);
    }

    public function salir(Request $request, ImpersonationSessionManager $sessions): JsonResponse
    {
        $data = $request->validate([
            'colegio_id' => ['required', 'string', 'exists:tenants,id'],
            'session_id' => ['nullable', 'uuid'],
        ]);

        /** @var User $superadmin */
        $superadmin = $request->user();
        $tenant = Tenant::findOrFail($data['colegio_id']);

        $session = $sessions->endLatest(
            $superadmin,
            $tenant,
            $data['session_id'] ?? null,
        );

        if ($session === null) {
            return response()->json(['data' => ['ended' => true, 'session_id' => null]]);
        }

        return response()->json(['data' => [
            'ended' => true,
            'session_id' => $session->session_id,
        ]]);
    }

    /** Ejecuta en tenant y restaura el contexto aun si el callback falla. */
    private function inTenant(Tenant $tenant, Closure $callback): mixed
    {
        $original = function_exists('tenant') ? tenant() : null;
        tenancy()->initialize($tenant);

        try {
            return $callback();
        } finally {
            $original !== null ? tenancy()->initialize($original) : tenancy()->end();
        }
    }
}
