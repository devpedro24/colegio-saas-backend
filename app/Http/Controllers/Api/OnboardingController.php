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
        return response()->json(TenantOnboarding::status($request->user()));
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
        abort_unless($request->user()->hasRole('rector'), 403);
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

        try {
            $processed = LogoProcessor::process($sourceBytes,
                (string) ($settings['aspect'] ?? 'auto'),
                (float) ($settings['zoom'] ?? 1),
                (float) ($settings['offset_x'] ?? 0),
                (float) ($settings['offset_y'] ?? 0));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['logo' => [$exception->getMessage()]]);
        }

        Storage::disk('tenant')->put(TenantOnboarding::logoPath(), $processed);
        DatosInstitucionales::query()->firstOrNew([])->fill([
            'logo_principal' => TenantOnboarding::logoPath(),
        ])->save();
        if (TenantOnboarding::institutionalComplete()) {
            ConfigurationGate::maybeActivate($request->user());
        }
        AuditLogger::tenant($request->user(), 'UPDATE', 'config.logo', (string) tenant()->getKey());

        return response()->json(TenantOnboarding::status($request->user()));
    }

    public function logo(): BinaryFileResponse
    {
        $path = TenantOnboarding::logoPath();
        abort_unless(Storage::disk('tenant')->exists($path), 404);

        return response()->file(Storage::disk('tenant')->path($path), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
