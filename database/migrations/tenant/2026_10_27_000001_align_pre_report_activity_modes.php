<?php

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Periodo;
use App\Services\FlexibleGradingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing open sheets follow the same rule as new configuration writes.
        // Closed academic history and manually entered components stay intact.
        $sheets = ComponenteEvaluacion::whereNotNull('preinforme_id')->where('modo', 'WEIGHTED_AVERAGE')
            ->select('asignacion_id', 'periodo_id')->distinct()->get();
        foreach ($sheets as $sheet) {
            DB::transaction(function () use ($sheet) {
                $period = Periodo::find($sheet->periodo_id);
                if (! $period) {
                    return;
                }
                $year = AnoLectivo::lockForUpdate()->find($period->ano_lectivo_id);
                $period = Periodo::lockForUpdate()->find($sheet->periodo_id);
                if ($year?->estado !== 'en_curso' || $period?->estado !== 'abierto'
                    || ! ($period->configuracion_notas['usar_preinformes'] ?? false)) {
                    return;
                }
                $simple = ComponenteEvaluacion::where('asignacion_id', $sheet->asignacion_id)
                    ->where('periodo_id', $period->id)->whereNotNull('preinforme_id')->where('modo', 'SIMPLE_AVERAGE')->first();
                if ($simple) {
                    app(FlexibleGradingService::class)->changeMode($simple, $period, 'WEIGHTED_AVERAGE', null);
                }
            });
        }
    }

    public function down(): void
    {
        // Restoring old calculation policies requires an explicit academic decision.
    }
};
