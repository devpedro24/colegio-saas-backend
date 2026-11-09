<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SedeAccess
{
    /** Resolve the authoritative account inside the parent school. */
    public function source(string $email, ?int $sourceId = null): ?User
    {
        $site = tenant();
        if (! $site?->parent_id) {
            return null;
        }

        return Tenant::findOrFail($site->parent_id)->run(function () use ($site, $email, $sourceId) {
            $sede = Sede::where('tenant_id', $site->id)->first();
            $user = User::where('email', $email)->when($sourceId, fn ($q) => $q->whereKey($sourceId))->first();
            if (! $sede || ! $user || $user->email === User::PLATFORM_SUPERADMIN_EMAIL || $user->status !== 'active') {
                return null;
            }
            if ($user->role !== 'rector' && ! ($sede->coordinador_id === $user->id
                && in_array($user->role, SedeConfiguration::COORDINATORS, true))) {
                return null;
            }
            $user->load('roles', 'permissions');

            return $user;
        });
    }

    private static function version(User $source): string
    {
        return hash('sha256', json_encode([$source->password, $source->role,
            $source->roles->pluck('name')->sort()->values()->all(),
            $source->permissions->pluck('name')->sort()->values()->all(),
            $source->two_factor_confirmed_at?->toIso8601String()], JSON_THROW_ON_ERROR));
    }

    public function localAccount(User $source): User
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($source) {
            Tenant::whereKey(tenant()->parent_id)->lockForUpdate()->firstOrFail();

            return DB::transaction(function () use ($source) {
                $user = User::where('school_user_id', $source->id)->first();
                abort_if(User::withTrashed()->where('email', $source->email)
                    ->when($user, fn ($query) => $query->whereKeyNot($user->id))->exists(), 409,
                    'Existe una cuenta local con ese correo. El administrador debe resolver la coincidencia de cuentas.');
                if (! $user) {
                    $user = new User(['email' => $source->email, 'password' => Str::random(64)]);
                }
                if ($user->exists && $user->school_credential_version !== self::version($source)) {
                    $user->tokens()->delete();
                }
                $user->forceFill(['name' => $source->name, 'email' => $source->email,
                    'phone' => $source->phone, 'role' => $source->role, 'status' => $source->status,
                    'must_change_password' => $source->must_change_password,
                    'school_user_id' => $source->id,
                    'school_credential_version' => self::version($source)])->save();
                $roles = $source->roles->pluck('name')->all();
                $user->syncRoles($roles);
                $user->syncPermissions($source->permissions->pluck('name')->all());

                return $user;
            });
        });
    }

    public function authorize(User $user): void
    {
        if ($user->email === User::PLATFORM_SUPERADMIN_EMAIL) {
            return; // EnsureMfaReady validates the platform support session first.
        }
        $site = tenant();
        if (! $site) {
            return;
        }
        if ($site->parent_id) {
            $sede = Tenant::findOrFail($site->parent_id)->run(fn () => Sede::where('tenant_id', $site->id)->first());
            abort_unless($sede, 403, 'Esta sede ya no está disponible.');
            $rector = false;
            if ($user->school_user_id) {
                $source = $this->source($user->email, (int) $user->school_user_id);
                abort_unless($source && hash_equals((string) $user->school_credential_version, self::version($source)),
                    401, 'Tu acceso cambió. Inicia sesión nuevamente.');
                abort_unless($user->role === $source->role, 401, 'Tu rol cambió. Inicia sesión nuevamente.');
                $rector = $source->role === 'rector';
            }
            $coordinator = $user->school_user_id && $sede->coordinador_id === (int) $user->school_user_id;
            // Legacy coordinators created directly in the child retain their assignment.
            $coordinator = $coordinator || (! $sede->coordinador_id && $sede->coordinador_email === $user->email
                && in_array($user->role, SedeConfiguration::COORDINATORS, true));
        } else {
            $sede = Sede::whereNull('tenant_id')->first();
            $rector = $user->role === 'rector';
            $coordinator = $sede?->coordinador_id === $user->id;
        }
        $allowed = match ($sede?->estado ?? 'activa') {
            'activa' => true, 'mantenimiento' => $rector || $coordinator, 'inactiva' => $rector, default => false,
        };
        abort_unless($allowed, 403, 'Esta sede está '.($sede?->estado ?? 'inactiva').'. Contacta al rector del colegio.');
    }
}
