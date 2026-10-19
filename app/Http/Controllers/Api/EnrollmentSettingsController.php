<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingreso\GradoInstitucional;
use App\Services\Ingreso\EnrollmentIntake;
use App\Services\Ingreso\EnrollmentSettings;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EnrollmentSettingsController extends Controller
{
    private function authorizeSettings(Request $request): void
    {
        app(EnrollmentIntake::class)->requirePlan();
        abort_unless($request->user()->can('ingreso.configurar'), 403);
    }

    public function show(Request $request, EnrollmentSettings $settings)
    {
        $this->authorizeSettings($request);

        return response()->json(['data' => $settings->present()]);
    }

    public function save(Request $request, EnrollmentSettings $settings)
    {
        $this->authorizeSettings($request);
        $data = $request->validate([
            'version' => 'required|integer|min:0', 'privacidad' => 'required|string|min:20|max:5000',
            'grados' => 'present|array|max:500', 'grados.*' => 'array:url_token,edad_min,edad_max',
            'grados.*.url_token' => 'required|string|size:24|distinct',
            'grados.*.edad_min' => 'present|nullable|integer|min:1|max:120',
            'grados.*.edad_max' => 'present|nullable|integer|min:1|max:120',
        ]);
        DB::transaction(function () use ($data, $request) {
            $current = DB::table('ingreso_configuracion')->lockForUpdate()->find(1);
            abort_if((int) $current->version !== $data['version'], 409, __('intake.settingsChanged'));
            $ids = OpaqueUrlToken::ids('ingreso-grado', array_column($data['grados'], 'url_token'), GradoInstitucional::query());
            abort_unless(count($ids) === count($data['grados']), 404);
            abort_if(count($ids) !== GradoInstitucional::count(), 409, __('intake.settingsChanged'));
            foreach ($data['grados'] as $index => $grade) {
                if (($grade['edad_min'] === null) !== ($grade['edad_max'] === null)
                    || ($grade['edad_min'] !== null && $grade['edad_max'] < $grade['edad_min'])) {
                    throw ValidationException::withMessages(['grados.'.$index.'.edad_max' => __('intake.ageConfiguration')]);
                }
                GradoInstitucional::whereKey($ids[$grade['url_token']])->update([
                    'edad_min' => $grade['edad_min'], 'edad_max' => $grade['edad_max'],
                ]);
            }
            DB::table('ingreso_configuracion')->where('id', 1)->update([
                'version' => $data['version'] + 1, 'privacidad' => $data['privacidad'],
                'requiere_revision' => false, 'updated_at' => now(),
            ]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'ingreso-configuracion', '1',
                ['version' => (int) $current->version], ['version' => $data['version'] + 1, 'grados' => $data['grados'],
                    'privacidad_hash' => hash('sha256', $data['privacidad'])]);
        });

        return response()->json(['data' => $settings->present()]);
    }
}
