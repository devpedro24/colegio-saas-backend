<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\DatosInstitucionales;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class TenantOnboarding
{
    public static function logoPath(): string
    {
        return (string) tenant()->getKey().'/branding/logo.png';
    }

    public static function logoUrl(): ?string
    {
        if (! Storage::disk('tenant')->exists(self::logoPath())) {
            return null;
        }

        $version = substr(hash_file('sha256', Storage::disk('tenant')->path(self::logoPath())), 0, 12);

        return '/api/branding/logo?v='.$version;
    }

    public static function institutionalComplete(): bool
    {
        $datos = DatosInstitucionales::query()->first();
        if ($datos === null || ! Storage::disk('tenant')->exists(self::logoPath())) {
            return false;
        }

        foreach (['nombre', 'nit', 'resolucion_men', 'direccion', 'telefono', 'correo'] as $field) {
            if (trim((string) $datos->{$field}) === '') {
                return false;
            }
        }

        return true;
    }

    public static function institutionRequired(User $user): bool
    {
        return ! self::institutionalComplete()
            && (tenant()?->status === Tenant::STATUS_CONFIGURING || $user->hasRole('rector'));
    }

    public static function status(User $user): array
    {
        $institutionRequired = self::institutionRequired($user);
        $passwordRequired = (bool) $user->must_change_password;
        $institution = DatosInstitucionales::query()->first();

        return [
            'required' => $passwordRequired || $institutionRequired,
            'password_required' => $passwordRequired,
            'institution_required' => $institutionRequired,
            'institution' => $institution ?? [
                'nombre' => tenant()?->legal_name ?: tenant()?->name,
                'nit' => tenant()?->nit,
                'resolucion_men' => null,
                'direccion' => null,
                'telefono' => null,
                'correo' => null,
            ],
            'logo_url' => self::logoUrl(),
        ];
    }
}
