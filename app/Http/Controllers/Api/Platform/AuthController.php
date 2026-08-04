<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Mfa\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Autenticacion de PLATAFORMA (dominio central, sin subdominio).
 *
 * Valida contra los usuarios de la BD central (superadministradores). No usa
 * roles/permisos de spatie (eso es del colegio/tenant): el superadmin es ROL-01
 * de plataforma.
 */
class AuthController extends Controller
{
    public function login(Request $request, TotpService $totp): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales invalidas.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['El usuario no esta activo.'],
            ]);
        }

        // Segundo factor (MFA/TOTP): si el superadmin lo tiene confirmado, exige un
        // codigo valido ANTES de emitir el token. Un usuario sin MFA no se ve afectado.
        if ($user->hasTwoFactorEnabled()) {
            $code = $data['code'] ?? null;

            if (empty($code)) {
                return response()->json([
                    'mfa_required' => true,
                    'message' => 'Se requiere el codigo de verificacion.',
                ], 422);
            }

            if (! $totp->verify((string) $user->getTwoFactorSecret(), $code)) {
                return response()->json([
                    'mfa_required' => true,
                    'message' => 'Codigo invalido.',
                ], 422);
            }
        }

        $token = $user->createToken('platform')->plainTextToken;

        AuditLogger::platform($user, 'LOGIN', 'auth', (string) $user->id);

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesion cerrada.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'google_email' => $user->google_email,
            'must_change_password' => (bool) $user->must_change_password,
            'mfa_enabled' => $user->hasTwoFactorEnabled(),
            'roles' => [$user->role ?? 'superadmin'],
            'permissions' => [],
            'is_platform' => true,
        ];
    }
}
