<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Mfa\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * MFA TOTP dentro del contexto del colegio/tenant (RN-RG-421 / D-MFA).
 *
 * Flujo: setup() genera y guarda el secreto SIN confirmar; confirm() valida el
 * primer codigo y habilita el segundo factor; disable() lo desactiva pidiendo
 * un codigo vigente o la contrasena. Opera sobre $request->user() del tenant.
 */
class MfaController extends Controller
{
    public function __construct(private readonly TotpService $totp)
    {
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        $secret = $this->totp->generateSecret();

        // Se guarda SIN confirmar: no cuenta como habilitado hasta confirm().
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

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

        return response()->json(['enabled' => true]);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
        ]);

        $user = $request->user();

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

        return response()->json(['enabled' => false]);
    }
}
