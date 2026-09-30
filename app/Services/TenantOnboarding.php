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

    public static function logoUrl(?DatosInstitucionales $institution = null): ?string
    {
        if (! Storage::disk('tenant')->exists(self::logoPath())) {
            return null;
        }

        $institution ??= DatosInstitucionales::query()->first();
        $version = substr($institution?->logo_version
            ?: hash_file('sha256', Storage::disk('tenant')->path(self::logoPath())), 0, 12);

        return '/api/branding/logo?v='.$version;
    }

    public static function institutionalComplete(): bool
    {
        return self::complete(DatosInstitucionales::query()->first());
    }

    private static function complete(?DatosInstitucionales $datos): bool
    {
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
        $institution = DatosInstitucionales::query()->first();
        $institutionRequired = ! self::complete($institution)
            && (tenant()?->status === Tenant::STATUS_CONFIGURING || $user->hasRole('rector'));
        $passwordRequired = (bool) $user->must_change_password;

        return [
            'required' => $passwordRequired || $institutionRequired,
            'password_required' => $passwordRequired,
            'institution_required' => $institutionRequired,
            'institution' => $institution?->only([
                'nombre', 'nit', 'resolucion_men', 'direccion', 'telefono', 'correo',
            ]) ?? [
                'nombre' => tenant()?->legal_name ?: tenant()?->name,
                'nit' => tenant()?->nit,
                'resolucion_men' => null,
                'direccion' => null,
                'telefono' => null,
                'correo' => null,
            ],
            'logo_url' => self::logoUrl($institution),
        ];
    }
}
