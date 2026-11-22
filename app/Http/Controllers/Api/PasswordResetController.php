<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PasswordResetCode;
use App\Notifications\PasswordWasReset;
use App\Services\PasswordResetOtp;
use App\Services\SedeAccess;
use App\Support\Audit\AuditLogger;
use App\Support\PasswordPolicy;
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
        if (app(SedeAccess::class)->source($data['email'])) {
            return Tenant::findOrFail(tenant()->parent_id)->run(fn () => $this->forgot($request));
        }
        $credentials = $this->credentials($data);
        $otp = app(PasswordResetOtp::class);
        $otp->lock($data['email'], function () use ($data, $credentials, $otp): void {
            $otp->throttle($data['email']);
            if ($data['email'] !== User::PLATFORM_SUPERADMIN_EMAIL) {
                // Instancia por petición: el repositorio de tokens usa la BD del tenant actual.
                try { (new PasswordBrokerManager(app()))->broker()->sendResetLink($credentials); }
                catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('Password reset link could not be sent.'); }
            }
        });
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
        if (app(SedeAccess::class)->source($data['email'])) {
            return Tenant::findOrFail(tenant()->parent_id)->run(fn () => $this->reset($request));
        }
        if ($data['email'] === User::PLATFORM_SUPERADMIN_EMAIL) {
            throw ValidationException::withMessages(['token' => 'El enlace es inválido o ha expirado.']);
        }
        DB::transaction(function () use ($data): void {
            // El bloqueo del usuario serializa usos simultáneos del mismo enlace.
            User::where('email', $data['email'])->lockForUpdate()->first();
            $status = (new PasswordBrokerManager(app()))->broker()->reset(
                $this->credentials($data),
                function (User $user, string $password): void {
                    $this->changePassword($user, $password);
                },
            );
            if ($status !== Password::PASSWORD_RESET) {
                throw ValidationException::withMessages(['token' => 'El enlace es inválido o ha expirado.']);
            }
        });

        $this->notifyChanged($data['email']);
        return response()->json(['message' => 'Contraseña actualizada. Inicia sesión de nuevo.']);
    }

    private function credentials(array $data): array
    {
        return [...$data, 'status' => User::STATUS_ACTIVE, ...(! tenancy()->initialized ? ['role' => 'superadmin'] : [])];
    }

    public function forgotOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        if (app(SedeAccess::class)->source($data['email'])) {
            return Tenant::findOrFail(tenant()->parent_id)->run(fn () => $this->forgotOtp($request));
        }
        $otp = app(PasswordResetOtp::class);
        $result = $otp->lock($data['email'], function () use ($data, $otp) {
            $otp->throttle($data['email']);
            $user = User::where($this->credentials($data))->where('email', '!=', User::PLATFORM_SUPERADMIN_EMAIL)->first();
            $result = $otp->issue($user, $data['email']);
            if ($user) {
                try { $user->notify(new PasswordResetCode($result['code'])); }
                catch (\Throwable) {
                    $otp->forget($data['email']);
                    \Illuminate\Support\Facades\Log::warning('Password reset code could not be sent.');
                }
            }
            return $result;
        });
        $this->audit(null, 'PASSWORD_RESET_OTP_REQUESTED');
        return response()->json(['message' => 'Si existe una cuenta activa con ese correo, recibirás un código para recuperar tu contraseña.',
            'challenge' => $result['challenge'], 'expires_in' => PasswordResetOtp::LIFETIME,
            'resend_after' => PasswordResetOtp::RESEND_AFTER])->header('Cache-Control', 'no-store, private');
    }

    public function resetOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'],
            'challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9]{24}$/D'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()]]);
        if (app(SedeAccess::class)->source($data['email'])) {
            return Tenant::findOrFail(tenant()->parent_id)->run(fn () => $this->resetOtp($request));
        }
        $otp = app(PasswordResetOtp::class);
        $valid = $otp->lock($data['email'], function () use ($data, $otp): bool {
            return DB::transaction(function () use ($data, $otp): bool {
                $user = User::where($this->credentials(['email' => $data['email']]))
                    ->where('email', '!=', User::PLATFORM_SUPERADMIN_EMAIL)->lockForUpdate()->first();
                if (! $otp->verify($user, $data['email'], $data['challenge'], $data['code'])) return false;
                $this->changePassword($user, $data['password']);
                (new PasswordBrokerManager(app()))->broker()->deleteToken($user);
                return true;
            });
        });
        if (! $valid) {
            $this->audit(null, 'PASSWORD_RESET_OTP_REJECTED');
            throw ValidationException::withMessages(['code' => ['El código no es válido, venció o agotó sus intentos. Solicita uno nuevo.']]);
        }
        $this->notifyChanged($data['email']);
        return response()->json(['message' => 'Contraseña actualizada. Inicia sesión de nuevo.']);
    }

    private function notifyChanged(string $email): void
    {
        // The password is already committed: a failed notice must not report a failed reset.
        try { User::where($this->credentials(['email' => $email]))->first()?->notify(new PasswordWasReset); }
        catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('Password reset notice could not be sent.'); }
    }

    private function changePassword(User $user, #[\SensitiveParameter] string $password): void
    {
        $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60),
            'must_change_password' => false,
            ...(tenancy()->initialized ? ['temporary_password' => null, 'temporary_password_expires_at' => null] : []),
        ])->save();
        $user->tokens()->delete();
        if (tenancy()->initialized && $user->hasRole('rector')) {
            tenant()->update(['rector_temporary_password' => null]);
        }
        if (! tenancy()->initialized) {
            // Close support sessions too, including legacy Bearer sessions.
            \App\Models\Impersonation::where('superadmin_id', $user->getKey())
                ->whereNull('ended_at')->update(['ended_at' => now()]);
        }
        app(PasswordResetOtp::class)->forget($user->email);
        $this->audit($user, 'PASSWORD_RESET');
    }

    private function audit(?User $user, string $action): void
    {
        $target = tenancy()->initialized ? 'tenant' : 'platform';
        AuditLogger::$target($user, $action, 'auth', $user ? (string) $user->id : null);
    }
}
