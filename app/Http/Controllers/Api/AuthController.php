<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Account\AccountPresenter;
use App\Support\Audit\AuditLogger;
use App\Support\Mfa\MfaVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Autenticacion por subdominio (dentro del contexto del colegio/tenant).
 * El login valida contra los usuarios de la BD del tenant y emite un token
 * Sanctum (guardado en la BD del tenant).
 */
class AuthController extends Controller
{
    public function login(Request $request, MfaVerifier $verifier): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || $user->email === User::PLATFORM_SUPERADMIN_EMAIL || ! Hash::check($data['password'], $user->password)) {
            AuditLogger::tenant(null, 'LOGIN_FAILED', 'auth', null, null, ['email' => $data['email']]);
            throw ValidationException::withMessages([
                'email' => ['Credenciales invalidas.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['El usuario no esta activo.'],
            ]);
        }

        // Segundo factor (MFA/TOTP): si el usuario lo tiene confirmado, exige un
        // codigo valido ANTES de emitir el token. Un usuario sin MFA no se ve afectado.
        if ($user->hasTwoFactorEnabled()) {
            $code = $data['code'] ?? null;

            if (empty($code)) {
                return response()->json([
                    'mfa_required' => true,
                    'message' => 'Se requiere el codigo de verificacion.',
                ], 422);
            }

            if (! $verifier->verify($user, $code)) {
                return response()->json([
                    'mfa_required' => true,
                    'message' => 'Codigo invalido.',
                ], 422);
            }
        }

        $token = $user->createToken('web', ['*'], now()->addHours(8))->plainTextToken;

        AuditLogger::tenant($user, 'LOGIN', 'auth', (string) $user->id);

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
        AuditLogger::tenant($request->user(), 'LOGOUT', 'auth', (string) $request->user()->id);
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesion cerrada.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return AccountPresenter::user($user);
    }
}
