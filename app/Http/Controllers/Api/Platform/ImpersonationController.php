<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Impersonation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
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
    private const SHADOW_EMAIL = 'superadmin@plataforma.local';
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
            'colegio_id' => ['required', 'string', 'exists:tenants,id'],
        ]);

        $superadmin = $request->user();
        $tenant = Tenant::findOrFail($data['colegio_id']);

        // Ventana de validez en UTC (el token y la fila comparten expiracion).
        $expiresAt = Carbon::now('UTC')->addHours(self::TTL_HOURS);

        // Sesion de suplantacion (central): una fila viva por (superadmin, colegio).
        Impersonation::updateOrCreate(
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
        $plainToken = $tenant->run(function () use ($superadmin, $tenant, $expiresAt) {
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
            $shadow->tokens()->where('name', self::TOKEN_NAME)->delete();

            $token = $shadow->createToken(self::TOKEN_NAME, ['*'], $expiresAt)->plainTextToken;

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

        return response()->json([
            'data' => [
                'colegio' => [
                    'id' => (string) $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ],
                'token' => $plainToken,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    /**
     * Vuelve a Plataforma: cierra la sesion de suplantacion y revoca el token
     * temporal del usuario sombra en el colegio.
     */
    public function salir(Request $request): JsonResponse
    {
        $data = $request->validate([
            'colegio_id' => ['required', 'string', 'exists:tenants,id'],
        ]);

        $superadmin = $request->user();
        $tenant = Tenant::findOrFail($data['colegio_id']);

        // Cierra la(s) sesion(es) viva(s) de este superadmin sobre el colegio.
        Impersonation::query()
            ->where('superadmin_id', $superadmin->id)
            ->where('tenant_id', (string) $tenant->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => Carbon::now('UTC')]);

        // Dentro del colegio: revoca los tokens de suplantacion y audita.
        $tenant->run(function () use ($superadmin, $tenant) {
            $shadow = User::where('email', self::SHADOW_EMAIL)->first();

            if ($shadow !== null) {
                $shadow->tokens()->where('name', self::TOKEN_NAME)->delete();
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

        return response()->json([
            'data' => ['ended' => true],
        ]);
    }
}
