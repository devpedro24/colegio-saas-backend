<?php

namespace App\Console\Commands;

use App\Models\Academico\SesionHorario;
use App\Models\Tenant;
use App\Support\OpaqueUrlToken;
use Illuminate\Console\Command;

/** Read-only diagnostic, not a concurrency/capacity certification. */
class BenchmarkAcademicSelectors extends Command
{
    protected $signature = 'rendimiento:selectores {--tenant= : Slug del colegio} {--iterations=30}';
    protected $description = 'Compara recorrido legado e índice para una clase; no escribe datos ni muestra IDs.';

    public function handle(): int
    {
        if (! $this->option('tenant')) {
            $this->error('Indica --tenant con el colegio que deseas medir.');
            return self::FAILURE;
        }
        $school = Tenant::where('slug', $this->option('tenant'))->firstOrFail();
        $iterations = max(1, min(100, (int) $this->option('iterations')));
        return $school->run(function () use ($iterations): int {
            $last = SesionHorario::orderByDesc('id')->first();
            if (! $last) {
                $this->error('No hay clases para comparar.');
                return self::FAILURE;
            }
            $token = OpaqueUrlToken::for('sesion-horario', $last->id);
            $this->info('Motor: '.\DB::connection()->getDriverName().'; clases: '.SesionHorario::count().'; iteraciones: '.$iterations);
            $cases = [
                'Recorrido legado' => function () use ($token) {
                    foreach (SesionHorario::orderBy('id')->cursor() as $row) {
                        if (hash_equals(OpaqueUrlToken::for('sesion-horario', $row->id), $token)) return $row;
                    }
                    return null;
                },
                'Índice y scope' => fn () => OpaqueUrlToken::find('sesion-horario', $token, SesionHorario::query()),
            ];
            $rows = [];
            foreach ($cases as $name => $read) {
                $read(); // Warm-up is excluded.
                $times = [];
                for ($i = 0; $i < $iterations; $i++) {
                    $start = hrtime(true);
                    $found = $read();
                    if (! $found || ! $last->is($found)) throw new \RuntimeException('La resolución no coincide.');
                    $times[] = (hrtime(true) - $start) / 1e6;
                }
                sort($times);
                $rows[] = [$name, round(array_sum($times) / count($times), 3), round($times[(int) ceil(count($times) * .95) - 1], 3)];
            }
            $this->table(['Método', 'Media ms', 'p95 ms'], $rows);
            return self::SUCCESS;
        });
    }
}
