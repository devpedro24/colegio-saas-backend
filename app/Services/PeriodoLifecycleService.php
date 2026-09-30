<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TenantDataChanged;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Periodo;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Sincroniza los períodos con la zona horaria académica configurada. */
final class PeriodoLifecycleService
{
    public static function today(): string
    {
        return Carbon::now(config('academico.timezone', 'America/Bogota'))->toDateString();
    }

    /**
     * Aplica solo transiciones automáticas. Una reapertura manual no vence por
     * fecha: el rector debe cerrarla expresamente después de la corrección.
     */
    public function synchronize(?AnoLectivo $year = null): int
    {
        $today = self::today();
        $years = $year ? collect([$year]) : AnoLectivo::query()->enCurso()->get();
        $changed = 0;

        foreach ($years as $item) {
            if ($item->estado !== AnoLectivo::ESTADO_EN_CURSO) {
                continue;
            }

            $events = DB::transaction(function () use ($item, $today): array {
                $events = [];
                foreach ($item->periodos()->lockForUpdate()->get() as $period) {
                    $target = null;
                    if ($period->estado === Periodo::ESTADO_PLANIFICADO) {
                        if ($period->fecha_fin->toDateString() < $today) {
                            // El scheduler pudo estar apagado durante todo el período.
                            $target = Periodo::ESTADO_CERRADO;
                        } elseif ($period->fecha_inicio->toDateString() <= $today) {
                            $target = Periodo::ESTADO_ABIERTO;
                        }
                    } elseif ($period->estado === Periodo::ESTADO_ABIERTO
                        && ! $period->reapertura_manual
                        && $period->fecha_fin->toDateString() < $today) {
                        $target = Periodo::ESTADO_CERRADO;
                    }

                    if ($target === null) {
                        continue;
                    }

                    $previous = ['estado' => $period->estado, 'reapertura_manual' => $period->reapertura_manual];
                    $period->update(['estado' => $target, 'reapertura_manual' => false]);
                    AuditLogger::tenant(
                        null, 'UPDATE', 'periodo', (string) $period->id,
                        $previous,
                        ['estado' => $period->estado, 'reapertura_manual' => false],
                        'Transición automática por fecha lectiva '.$today.'.',
                    );
                    $events[] = $period->nombre;
                }

                return $events;
            });

            $changed += count($events);
            foreach ($events as $name) {
                try {
                    TenantDataChanged::dispatch('periodo', 'updated', $name);
                } catch (\Throwable) {
                }
            }
        }

        return $changed;
    }
}
