<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Mfa\MfaPolicy;
use App\Support\Mfa\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * MFA TOTP de PLATAFORMA (dominio central, superadministradores). Es OBLIGATORIO
 * para el superadmin (RN-RG-421 / D-MFA).
 *
 * Flujo: setup() genera y guarda el secreto SIN confirmar; confirm() valida el
 * primer codigo y habilita el segundo factor; disable() lo desactiva pidiendo
 * un codigo vigente o la contrasena. Opera sobre $request->user() central.
 */
class MfaController extends Controller
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly MfaPolicy $policy,
    ) {}

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'mfa' => ['MFA ya esta activo. Desactivalo antes de iniciar una nueva configuracion.'],
            ]);
        }

        $secret = $this->totp->generateSecret();

        // Se guarda SIN confirmar: no cuenta como habilitado hasta confirm().
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLogger::platform(
            $user,
            'MFA_SETUP_STARTED',
            'auth.mfa',
            (string) $user->id,
            ['enabled' => false],
            ['enabled' => false, 'pending' => true],
        );

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $this->totp->otpauthUrl($user->email, $secret),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user->getTwoFactorSecret()) {
            throw ValidationException::withMessages([
                'code' => ['No hay un proceso de activacion de MFA en curso.'],
            ]);
        }

        if (! $this->totp->verify($user->getTwoFactorSecret(), $data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['El codigo es invalido.'],
            ]);
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
        ])->save();

        AuditLogger::platform(
            $user,
            'MFA_ENABLED',
            'auth.mfa',
            (string) $user->id,
            ['enabled' => false],
            ['enabled' => true],
        );

        return response()->json(['enabled' => true]);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        if ($user instanceof User && $this->policy->isRequired($user)) {
            throw ValidationException::withMessages([
                'mfa' => ['MFA es obligatorio para tu rol y no puede desactivarse.'],
            ]);
        }

        $confirmedByCode = ! empty($data['code'])
            && $user->getTwoFactorSecret()
            && $this->totp->verify($user->getTwoFactorSecret(), $data['code']);

        $confirmedByPassword = ! empty($data['password'])
            && Hash::check($data['password'], $user->password);

        if (! $confirmedByCode && ! $confirmedByPassword) {
            throw ValidationException::withMessages([
                'code' => ['Debes confirmar con un codigo vigente o tu contrasena.'],
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        AuditLogger::platform(
            $user,
            'MFA_DISABLED',
            'auth.mfa',
            (string) $user->id,
            ['enabled' => true],
            ['enabled' => false],
        );

        return response()->json(['enabled' => false]);
    }
}
