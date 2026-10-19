<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\Grado;
use App\Models\Ingreso\Campana;
use App\Models\Ingreso\GradoInstitucional;
use App\Models\Ingreso\Solicitud;
use App\Support\OpaqueUrlToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EnrollmentSettings
{
    public function record(): object
    {
        return DB::table('ingreso_configuracion')->find(1);
    }

    public function present(): array
    {
        $settings = $this->record();

        return ['version' => (int) $settings->version, 'privacidad' => $settings->privacidad ?? '',
            'requiere_revision' => (bool) $settings->requiere_revision,
            'zona_horaria' => tenant()->zonaHorariaInstitucional(),
            'grados' => GradoInstitucional::orderBy('nivel')->orderBy('id')->get()->map(fn ($g) => [
                'url_token' => OpaqueUrlToken::for('ingreso-grado', $g->id), 'nombre' => $g->nombre,
                'nivel' => $g->nivel, 'edad_min' => $g->edad_min, 'edad_max' => $g->edad_max,
            ])->all()];
    }

    /** Legacy choices remain readable until the school's first explicit save. */
    public function configuration(Campana $campaign): array
    {
        $settings = $this->record();
        $config = $campaign->configuracion;
        $config['documentos'] = array_map(fn ($document) => array_diff_key($document, ['max_mb' => true]), $config['documentos']);
        $config['privacidad'] = $settings->version > 0 ? $settings->privacidad : ($config['privacidad'] ?? $settings->privacidad ?? '');
        $grades = Grado::with('ingresoGrado')->where('ano_lectivo_id', $campaign->ano_lectivo_id)->get()
            ->keyBy(fn ($g) => OpaqueUrlToken::for('grado', $g->id));
        $config['grados'] = array_map(function ($g) use ($grades, $settings) {
            $rule = $grades->get($g['token'])?->ingresoGrado;
            $legacy = (int) $settings->version === 0 && isset($g['edad_min'], $g['edad_max']);
            unset($g['edad_corte']);
            $g['edad_min'] = $legacy ? $g['edad_min'] : $rule?->edad_min;
            $g['edad_max'] = $legacy ? $g['edad_max'] : $rule?->edad_max;

            return $g;
        }, $config['grados']);

        return $config;
    }

    public function ageValidation(Solicitud $application, array $data, ?int $gradeId = null): array
    {
        $gradeId ??= $application->grado_aprobado_id ?? $application->grado_id;
        $previous = $application->validacion_edad;
        // A birthday or a new school policy must not revoke an already accepted submission.
        if ($previous && $previous['nacimiento'] === $data['nacimiento'] && (int) $previous['grado_id'] === $gradeId) {
            return $previous;
        }
        $timezone = tenant()->zonaHorariaInstitucional();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $birth = CarbonImmutable::parse($data['nacimiento'], $timezone)->startOfDay();
        $age = (int) $birth->diffInYears($today, false);
        $rule = collect($this->configuration($application->campana)['grados'])->firstWhere('token', OpaqueUrlToken::for('grado', $gradeId));
        $min = $rule['edad_min'] ?? null;
        $max = $rule['edad_max'] ?? null;
        if ($min !== null && $max !== null && ($age < $min || $age > $max)) {
            throw ValidationException::withMessages(['datos.nacimiento' => __('intake.ageOutsideGrade', ['min' => $min, 'max' => $max, 'age' => $age])]);
        }

        return ['grado_id' => $gradeId, 'nacimiento' => $data['nacimiento'], 'edad' => $age,
            'fecha' => $today->toDateString(), 'edad_min' => $min, 'edad_max' => $max,
            'configuracion_version' => (int) $this->record()->version];
    }
}
