<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\TenantDataChanged;
use App\Http\Controllers\Controller;
use App\Models\Academico\DatosInstitucionales;
use App\Services\ConfigurationGate;
use App\Services\LogoProcessor;
use App\Services\TenantOnboarding;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OnboardingController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $status = TenantOnboarding::status($request->user());
        if ($request->user()->esSuperadminPlataforma() && $status['logo_url']) {
            $status['logo_url'] = str_replace('/branding/logo', '/school-branding/logo', $status['logo_url']);
        }
        return response()->json($status);
    }

    public function institution(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('rector'), 403);
        abort_if($request->user()->must_change_password, 423, 'Cambia primero la contraseña temporal.');

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'nit' => ['required', 'string', 'max:60'],
            'resolucion_men' => ['required', 'string', 'max:255'],
            'direccion' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:60'],
            'correo' => ['required', 'email', 'max:255'],
        ]);

        $datos = DatosInstitucionales::query()->firstOrNew([]);
        $before = $datos->exists ? $datos->only(array_keys($data)) : null;
        $datos->fill($data)->save();
        if (TenantOnboarding::institutionalComplete()) {
            ConfigurationGate::maybeActivate($request->user());
        }

        AuditLogger::tenant($request->user(), $before ? 'UPDATE' : 'CREATE',
            'config.datos_institucionales', (string) $datos->id, $before, $datos->only(array_keys($data)));

        try {
            TenantDataChanged::dispatch('datos_institucionales', 'updated', $datos->nombre);
        } catch (\Throwable) {
        }

        return response()->json(TenantOnboarding::status($request->user()));
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        abort_unless($request->user()->esSuperadminPlataforma() ||
            ($request->user()->hasRole('rector') && $request->user()->can('academico.configurar')), 403);
        abort_if($request->user()->must_change_password, 423, 'Cambia primero la contraseña temporal.');

        $settings = $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:5120'],
            'aspect' => ['sometimes', 'in:auto,square'],
            'zoom' => ['sometimes', 'numeric', 'between:1,3'],
            'offset_x' => ['sometimes', 'numeric', 'between:-1,1'],
            'offset_y' => ['sometimes', 'numeric', 'between:-1,1'],
        ]);

        $file = $request->file('logo');
        $sourceBytes = file_get_contents($file->getRealPath());
        $info = getimagesizefromstring($sourceBytes ?: '');
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
            || $info[0] < 200 || $info[1] < 100 || $info[0] > 6000 || $info[1] > 6000) {
            throw ValidationException::withMessages([
                'logo' => ['Usa una imagen PNG o JPG de al menos 200 × 100 píxeles y máximo 6000 × 6000.'],
            ]);
        }

        $quarantine = \App\Support\Storage\StorageService::tenantFolder(tenant()).'/branding/quarantine/'.bin2hex(random_bytes(16));
        try {
            abort_unless(Storage::disk('tenant')->put($quarantine, $sourceBytes), 500);
            app(\App\Support\Storage\FileScanner::class)->scan('tenant', $quarantine);
            $processed = LogoProcessor::process($sourceBytes,
                (string) ($settings['aspect'] ?? 'auto'),
                (float) ($settings['zoom'] ?? 1),
                (float) ($settings['offset_x'] ?? 0),
                (float) ($settings['offset_y'] ?? 0));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['logo' => [$exception->getMessage()]]);
        } catch (\App\Support\Storage\StorageException $exception) {
            throw ValidationException::withMessages(['logo' => [$exception->getMessage()]]);
        } finally {
            Storage::disk('tenant')->delete($quarantine);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'school-logo-');
        try {
            file_put_contents($temporary, $processed);
            $stored = app(\App\Support\Storage\StorageService::class)->store(
                new \Illuminate\Http\UploadedFile($temporary, 'logo.png', 'image/png', null, true),
                'branding/logos', $request->user()->email, tenant(), 5 * 1024 * 1024);
        } catch (\App\Support\Storage\StorageException $exception) {
            throw ValidationException::withMessages(['logo' => [$exception->getMessage()]]);
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
        $previous = DatosInstitucionales::query()->first()?->logo_principal;
        DatosInstitucionales::query()->firstOrNew([])->fill([
            'logo_principal' => $stored->path,
            'logo_version' => hash('sha256', $processed),
        ])->save();
        if ($previous && $previous !== $stored->path) {
            \App\Models\StoredFile::where('tenant_id', tenant()->getKey())->where('path', $previous)->delete();
        }
        if (TenantOnboarding::institutionalComplete()) {
            ConfigurationGate::maybeActivate($request->user());
        }
        AuditLogger::tenant($request->user(), 'UPDATE', 'config.logo', (string) tenant()->getKey());

        return response()->json(TenantOnboarding::status($request->user()));
    }

    public function logo(Request $request): BinaryFileResponse
    {
        $path = TenantOnboarding::logoReadPath();
        abort_unless(Storage::disk('tenant')->exists($path), 404);

        $institution = DatosInstitucionales::query()->first();
        $version = substr($institution?->logo_version
            ?: hash_file('sha256', Storage::disk('tenant')->path($path)), 0, 12);
        abort_if($request->has('v') && $request->query('v') !== $version, 404);

        $response = response()->file(Storage::disk('tenant')->path($path), [
            'Content-Type' => 'image/png',
            'Cache-Control' => $request->has('v') ? 'public, max-age=31536000, immutable' : 'public, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag($version);
        $response->isNotModified($request);

        return $response;
    }
}
