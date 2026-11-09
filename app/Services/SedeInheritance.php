<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\EspacioFisico;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Jornada;
use App\Models\Academico\Nivel;
use App\Models\Academico\Sede;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SedeInheritance
{
    public const CATEGORIES = ['anos', 'periodos', 'niveles', 'grados', 'jornadas', 'bloques', 'grupos', 'espacios', 'escalas', 'metodos', 'modelos', 'datos'];

    public const DEPENDENCIES = [
        'periodos' => ['anos'], 'niveles' => ['anos'], 'grados' => ['anos', 'niveles'],
        'jornadas' => ['anos'], 'bloques' => ['anos', 'jornadas'],
        'grupos' => ['anos', 'niveles', 'grados', 'jornadas'], 'espacios' => ['anos'],
        'escalas' => ['anos'], 'metodos' => ['anos'], 'modelos' => ['anos'],
    ];

    public static function validate(array $categories): void
    {
        if ($categories === [] || array_diff($categories, self::CATEGORIES)) {
            throw ValidationException::withMessages(['categorias' => 'Selecciona la configuración que deseas heredar.']);
        }
        foreach ($categories as $category) {
            if (array_diff(self::DEPENDENCIES[$category] ?? [], $categories)) {
                throw ValidationException::withMessages(['categorias' => 'Incluye las dependencias de '.$category.': '.implode(', ', self::DEPENDENCIES[$category]).'.']);
            }
        }
    }

    public function capture(array $categories): array
    {
        self::validate($categories);
        $snapshot = app(SedeProvisioner::class)->capturarConfiguracion(tenant(), true);
        $snapshot = array_intersect_key($snapshot, array_flip($categories));
        if (isset($snapshot['anos']) && ! in_array('periodos', $categories, true)) {
            foreach ($snapshot['anos'] as &$year) {
                $year['periodos'] = [];
            }
            unset($year);
        }
        if (isset($snapshot['niveles']) && ! in_array('grados', $categories, true)) {
            foreach ($snapshot['niveles'] as &$level) {
                $level['grados'] = [];
            }
            unset($level);
        }
        foreach (['jornadas' => Jornada::class, 'bloques' => BloqueHorario::class,
            'espacios' => EspacioFisico::class, 'grupos' => Grupo::class] as $key => $model) {
            if (in_array($key, $categories, true)) {
                $snapshot[$key] = $model::orderBy('id')->get()->toArray();
            }
        }

        return $snapshot;
    }

    public function apply(array $snapshot): void
    {
        DB::transaction(function () use ($snapshot) {
            app(SedeProvisioner::class)->applySnapshot($snapshot);
            $years = [];
            $grades = [];
            $shifts = [];
            foreach ($snapshot['anos'] ?? [] as $year) {
                $years[$year['id']] = AnoLectivo::where('nombre', $year['nombre'])->firstOrFail()->id;
            }
            foreach ($snapshot['niveles'] ?? [] as $level) {
                $local = Nivel::where('ano_lectivo_id', $years[$level['ano_lectivo_id']] ?? null)
                    ->where('nivel_educativo', $level['nivel_educativo'])->where('nombre', $level['nombre'])->firstOrFail();
                foreach ($level['grados'] ?? [] as $grade) {
                    $grades[$grade['id']] = Grado::where('nivel_id', $local->id)->where('codigo', $grade['codigo'])->firstOrFail()->id;
                }
            }
            $localSite = Sede::whereNull('tenant_id')->first()
                ?? Sede::create(['nombre' => tenant()->name, 'estado' => 'activa']);
            foreach ($snapshot['jornadas'] ?? [] as $shift) {
                $local = Jornada::firstOrCreate(['ano_lectivo_id' => $years[$shift['ano_lectivo_id']] ?? null,
                    'sede_id' => $localSite->id, 'nombre' => $shift['nombre']],
                    ['hora_inicio' => $shift['hora_inicio'], 'hora_fin' => $shift['hora_fin'], 'estado' => $shift['estado']]);
                $shifts[$shift['id']] = $local->id;
            }
            foreach ($snapshot['bloques'] ?? [] as $block) {
                BloqueHorario::firstOrCreate(['ano_lectivo_id' => $years[$block['ano_lectivo_id']] ?? null,
                    'jornada_id' => $shifts[$block['jornada_id']], 'nombre' => $block['nombre']],
                    ['hora_inicio' => $block['hora_inicio'], 'hora_fin' => $block['hora_fin'],
                        'es_descanso' => $block['es_descanso'], 'estado' => $block['estado']]);
            }
            foreach ($snapshot['espacios'] ?? [] as $space) {
                EspacioFisico::firstOrCreate(['ano_lectivo_id' => $years[$space['ano_lectivo_id']] ?? null,
                    'sede_id' => $localSite->id, 'nombre' => $space['nombre']],
                    ['tipo' => $space['tipo'], 'capacidad' => $space['capacidad'],
                        'ubicacion' => $space['ubicacion'], 'estado' => $space['estado']]);
            }
            foreach ($snapshot['grupos'] ?? [] as $group) {
                Grupo::firstOrCreate(['ano_lectivo_id' => $years[$group['ano_lectivo_id']],
                    'grado_id' => $grades[$group['grado_id']], 'nombre' => $group['nombre']],
                    ['sede_id' => $localSite->id, 'jornada_id' => $shifts[$group['jornada_id']] ?? null,
                        'cupo_maximo' => $group['cupo_maximo'], 'estado' => $group['estado']]);
            }
        });
    }
}
