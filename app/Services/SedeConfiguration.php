<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ingreso\EnrollmentSubmission;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SedeConfiguration
{
    public const COORDINATORS = ['coord_academico', 'coord_convivencia', 'coord_combinado'];

    public static function domainHost(Tenant $site): string
    {
        $domain = $site->domains()->first()?->domain ?? $site->slug;
        $short = $site->parent_id ? $site->slug.'.'.Tenant::findOrFail($site->parent_id)->slug : $site->slug;
        if ($domain !== $short) {
            return $domain;
        }
        $base = collect(config('tenancy.central_domains'))->first(
            fn ($host) => request()->getHost() === $host || str_ends_with(request()->getHost(), '.'.$host),
            config('tenancy.central_domains')[0]);

        return $domain.'.'.$base;
    }

    public function validate(Request $request, ?Sede $sede = null): array
    {
        abort_if(tenant()->parent_id, 403, 'Administra las sedes desde el colegio principal.');
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120', Rule::unique('sedes', 'nombre')->ignore($sede?->id)->whereNull('deleted_at')],
            'slug' => [$sede ? 'sometimes' : 'required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'departamento' => ['nullable', 'string', Rule::in(array_column(EnrollmentSubmission::geography(), 'codigo'))],
            'municipio' => ['nullable', 'required_with:departamento', 'string', 'max:5'],
            'coordinador_token' => ['nullable', 'string', 'size:24'],
            'capacidad_maxima' => ['required', 'integer', 'min:1', 'max:100000'],
            'estado' => ['required', Rule::in(['activa', 'inactiva', 'mantenimiento'])],
            'heredar' => ['sometimes', 'boolean'],
            'categorias' => ['sometimes', 'array'],
            'categorias.*' => ['string', 'distinct', Rule::in(SedeInheritance::CATEGORIES)],
        ]);
        if (array_key_exists('telefono', $data)) {
            $data['telefono'] = ContactNumbers::normalize($data['telefono'],
                ContactNumbers::countryForTimezone(tenant()->zonaHorariaInstitucional()), 'telefono');
        }
        if (! empty($data['municipio'])) {
            $department = collect(EnrollmentSubmission::geography())->firstWhere('codigo', $data['departamento'] ?? '');
            if (! in_array($data['municipio'], array_column($department['municipios'] ?? [], 'codigo'), true)) {
                throw ValidationException::withMessages(['municipio' => 'Selecciona un municipio del departamento indicado.']);
            }
        }
        if (array_key_exists('coordinador_token', $data)) {
            $coordinator = $data['coordinador_token'] ? OpaqueUrlToken::find('usuario', $data['coordinador_token'], User::query()) : null;
            abort_if($data['coordinador_token'] && ! $coordinator, 404);
            if ($coordinator && ($coordinator->status !== 'active'
                || ! in_array($coordinator->role, self::COORDINATORS, true))) {
                throw ValidationException::withMessages(['coordinador_token' => 'Selecciona un coordinador activo de este colegio.']);
            }
            $data['coordinador_id'] = $coordinator?->id;
            $data['coordinador_name'] = $coordinator?->name;
            $data['coordinador_email'] = $coordinator?->email;
            unset($data['coordinador_token']);
        }
        if ($data['heredar'] ?? false) {
            SedeInheritance::validate($data['categorias'] ?? []);
        }
        $target = $sede?->tenant_id ? Tenant::findOrFail($sede->tenant_id) : tenant();
        if (isset($data['slug'])) {
            $domain = $sede && ! $sede->tenant_id
                ? preg_replace('/^[^.]+/', $data['slug'], tenant()->domains()->firstOrFail()->domain)
                : $data['slug'].'.'.tenant()->domains()->firstOrFail()->domain;
            $central = DB::connection(config('tenancy.database.central_connection'));
            if ($central->table('domains')->where('domain', $domain)->when($sede, fn ($q) => $q->where('tenant_id', '!=', $target->id))->exists()
                || Tenant::where('slug', $data['slug'])->where('parent_id', $sede && ! $sede->tenant_id ? null : tenant()->id)
                    ->when($sede, fn ($q) => $q->where('id', '!=', $target->id))->exists()) {
                throw ValidationException::withMessages(['slug' => 'Ese subdominio ya está en uso.']);
            }
        }

        return $data;
    }

    public function synchronize(Sede $sede, array $data): void
    {
        $target = $sede->tenant_id ? Tenant::findOrFail($sede->tenant_id) : tenant();
        $central = DB::connection(config('tenancy.database.central_connection'));
        $central->transaction(function () use ($target, $sede, $data) {
            $target->refresh();
            $target->setInternal('db_name', $target->database()->getName());
            $target->name = $sede->nombre;
            if (isset($data['slug']) && $data['slug'] !== $target->slug) {
                $oldDomain = $target->domains()->firstOrFail();
                $newDomain = $sede->tenant_id
                    ? $data['slug'].'.'.tenant()->domains()->firstOrFail()->domain
                    : preg_replace('/^[^.]+/', $data['slug'], $oldDomain->domain);
                if (! $sede->tenant_id) {
                    foreach ($target->sedes()->with('domains')->get() as $child) {
                        foreach ($child->domains as $domain) {
                            if (str_ends_with($domain->domain, '.'.$oldDomain->domain)) {
                                $domain->update(['domain' => substr($domain->domain, 0, -strlen($oldDomain->domain)).$newDomain]);
                            }
                        }
                    }
                }
                $oldDomain->update(['domain' => $newDomain]);
                $target->slug = $data['slug'];
            }
            $target->save();
        });
    }
}
