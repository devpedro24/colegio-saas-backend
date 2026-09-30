<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Mfa\MfaVerifier;
use App\Support\Mfa\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class MfaController extends Controller
{
    public function __construct(private readonly TotpService $totp) {}

    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        $this->checkPassword($user, $data['password']);
        abort_if($user->must_change_password, 403, 'Primero cambia tu contraseña temporal.');
        $secret = $user->getConnection()->transaction(function () use ($user) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->hasTwoFactorEnabled(), 409, 'La verificación en dos pasos ya está activa.');
            $secret = $this->totp->generateSecret();
            $locked->forceFill(['two_factor_secret' => $secret])->save();

            return $secret;
        });

        return response()->json(['secret' => $secret, 'otpauth_url' => $this->totp->otpauthUrl($user->email, $secret)]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/D']]);
        $user = $request->user();
        abort_if($user->must_change_password, 403, 'Primero cambia tu contraseña temporal.');
        $codes = $user->getConnection()->transaction(function () use ($user, $data) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->hasTwoFactorEnabled(), 409, 'La verificación en dos pasos ya está activa.');
            if (! $locked->getTwoFactorSecret() || ! $this->totp->verify($locked->getTwoFactorSecret(), $data['code'])) {
                throw ValidationException::withMessages(['code' => ['El código no es válido.']]);
            }
            $codes = array_map(fn () => bin2hex(random_bytes(10)), range(1, 8));
            $locked->forceFill(['two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => json_encode(array_map(fn ($code) => hash('sha256', $code), $codes))])->save();
            $token = $user->currentAccessToken();
            $locked->tokens()->where('id', '!=', $token?->id ?? 0)->delete();
            if ($token instanceof PersonalAccessToken) {
                $token->forceFill(['name' => tenancy()->initialized ? 'web' : 'platform',
                    'abilities' => ['*'], 'expires_at' => now()->addHours(8)])->save();
            }

            return array_map(fn ($code) => implode('-', str_split($code, 5)), $codes);
        });
        $this->audit($user, 'MFA_ENABLED');

        return response()->json(['mfa_enabled' => true, 'recovery_codes' => $codes]);
    }

    public function disable(Request $request, MfaVerifier $verifier): JsonResponse
    {
        $user = $request->user();
        $user->refresh();
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:32']]);
        $this->checkPassword($user, $data['password']);
        if (! $user->hasTwoFactorEnabled() || ! $verifier->verify($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['El código no es válido.']]);
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null])->save();
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id ?? 0)->delete();
        $this->audit($user, 'MFA_DISABLED');

        return response()->json(['mfa_enabled' => false]);
    }

    private function checkPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['La contraseña es incorrecta.']]);
        }
    }

    private function audit(User $user, string $action): void
    {
        $method = tenancy()->initialized ? 'tenant' : 'platform';
        AuditLogger::$method($user, $action, 'account', (string) $user->id);
    }
}
