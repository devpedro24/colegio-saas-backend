<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodos_sumatorios_legado', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ano_lectivo_id');
            $table->longText('ano_anterior');
            $table->longText('periodos_anteriores');
            $table->timestamp('created_at');
            $table->unique('ano_lectivo_id');
        });

        foreach (DB::table('anos_lectivos')->where('tiene_quinto_periodo', true)->get() as $year) {
            $periods = DB::table('periodos')->where('ano_lectivo_id', $year->id)->orderBy('orden')->get();
            $extras = $periods->filter(fn ($period) => $period->orden > $year->num_periodos);
            if ($extras->isEmpty()) {
                continue;
            }
            if ($extras->count() !== 1 || $extras->first()->orden !== $year->num_periodos + 1) {
                throw new RuntimeException("El año {$year->nombre} tiene períodos adicionales ambiguos; no se convirtió.");
            }
            $extra = $extras->first();
            $last = $periods->firstWhere('orden', $year->num_periodos);
            if (! $last || $extra->deleted_at !== null || $last->deleted_at !== null
                || $extra->estado !== 'planificado'
                || ! Carbon::parse($last->fecha_fin)->addDay()->isSameDay($extra->fecha_inicio)
                || ! Carbon::parse($extra->fecha_fin)->isSameDay($year->fecha_fin)) {
                throw new RuntimeException("El período adicional del año {$year->nombre} tiene datos o fechas que requieren revisión manual; no se eliminó.");
            }

            // Un período usado en evaluación nunca se convierte automáticamente.
            foreach (Schema::getTables() as $tableInfo) {
                $table = is_array($tableInfo) ? $tableInfo['name'] : $tableInfo->name;
                if ($table === 'periodos') {
                    continue;
                }
                foreach (['periodo_id', 'period_id'] as $field) {
                    if (Schema::hasColumn($table, $field) && DB::table($table)->where($field, $extra->id)->exists()) {
                        throw new RuntimeException("El período adicional del año {$year->nombre} ya tiene registros relacionados en {$table}; se conservó para revisión.");
                    }
                }
            }

            DB::table('periodos_sumatorios_legado')->insert([
                'ano_lectivo_id' => $year->id,
                'ano_anterior' => json_encode($year, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'periodos_anteriores' => json_encode($periods, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            $regular = $periods->filter(fn ($period) => $period->orden <= $year->num_periodos)->values();
            if ($regular->count() === (int) $year->num_periodos && $regular->every(fn ($period) => $period->peso !== null)) {
                $totalCents = $regular->sum(fn ($period) => (int) round((float) $period->peso * 100));
                if ($totalCents > 0) {
                    $remainingCents = 10000;
                    foreach ($regular as $index => $period) {
                        $cents = $index === $regular->count() - 1
                            ? $remainingCents
                            : (int) round((float) $period->peso * 100 / $totalCents * 10000);
                        DB::table('periodos')->where('id', $period->id)->update(['peso' => number_format($cents / 100, 2, '.', '')]);
                        $remainingCents -= $cents;
                    }
                }
            }
            DB::table('periodos')->where('id', $last->id)->update(['fecha_fin' => $year->fecha_fin]);
            DB::table('periodos')->where('id', $extra->id)->delete();
        }

        Schema::table('anos_lectivos', function (Blueprint $table): void {
            $table->renameColumn('tiene_quinto_periodo', 'periodo_sumatorio');
        });
    }

    public function down(): void
    {
        if (DB::table('periodos_sumatorios_legado')->exists()) {
            throw new RuntimeException('Hay períodos convertidos. Recupera primero los datos archivados; no se ejecutó un rollback destructivo.');
        }
        Schema::table('anos_lectivos', function (Blueprint $table): void {
            $table->renameColumn('periodo_sumatorio', 'tiene_quinto_periodo');
        });
        Schema::drop('periodos_sumatorios_legado');
    }
};
