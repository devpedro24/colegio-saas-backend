<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PasswordPolicy;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $credentials = $this->credentials($data);
        if ($data['email'] !== User::PLATFORM_SUPERADMIN_EMAIL) {
            // Instancia por petición: el repositorio de tokens usa la BD del tenant actual.
            (new PasswordBrokerManager(app()))->broker()->sendResetLink($credentials);
        }
        $this->audit(null, 'PASSWORD_RESET_REQUESTED');

        return response()->json(['message' => 'Si existe una cuenta activa con ese correo, recibirás un enlace para recuperar tu contraseña.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);
        if ($data['email'] === User::PLATFORM_SUPERADMIN_EMAIL) {
            throw ValidationException::withMessages(['token' => 'El enlace es inválido o ha expirado.']);
        }
        DB::transaction(function () use ($data): void {
            // El bloqueo del usuario serializa usos simultáneos del mismo enlace.
            User::where('email', $data['email'])->lockForUpdate()->first();
            $status = (new PasswordBrokerManager(app()))->broker()->reset(
                $this->credentials($data),
                function (User $user, string $password): void {
                    $user->forceFill([
                        'password' => Hash::make($password),
                        'remember_token' => Str::random(60),
                        'must_change_password' => false,
                        ...(tenancy()->initialized ? ['temporary_password' => null] : []),
                    ])->save();
                    $user->tokens()->delete();
                    $this->audit($user, 'PASSWORD_RESET');
                },
            );
            if ($status !== Password::PASSWORD_RESET) {
                throw ValidationException::withMessages(['token' => 'El enlace es inválido o ha expirado.']);
            }
        });

        return response()->json(['message' => 'Contraseña actualizada. Inicia sesión de nuevo.']);
    }

    private function credentials(array $data): array
    {
        return [...$data, 'status' => User::STATUS_ACTIVE, ...(! tenancy()->initialized ? ['role' => 'superadmin'] : [])];
    }

    private function audit(?User $user, string $action): void
    {
        $target = tenancy()->initialized ? 'tenant' : 'platform';
        AuditLogger::$target($user, $action, 'auth', $user ? (string) $user->id : null);
    }
}
