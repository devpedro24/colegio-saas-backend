<?php

declare(strict_types=1);

use App\Services\SieeConfiguration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // Incluye años cerrados y archivados. Solo cambia la configuración
            // de presentación; no modifica calificaciones ni resultados exactos.
            DB::table('anos_lectivos')->orderBy('id')->chunkById(100, function ($years): void {
                foreach ($years as $year) {
                    // NULL significa que el SIEE aún no se ha configurado.
                    if ($year->siee === null) {
                        continue;
                    }
                    $configuration = json_decode((string) $year->siee, true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($configuration)) {
                        throw new RuntimeException('Configuración SIEE inválida en un año lectivo.');
                    }
                    if ($configuration === []) {
                        continue;
                    }
                    if (($configuration['redondeo'] ?? null) === SieeConfiguration::RESULT_ROUNDING) {
                        continue;
                    }
                    $configuration['redondeo'] = SieeConfiguration::RESULT_ROUNDING;
                    DB::table('anos_lectivos')->where('id', $year->id)->update([
                        'siee' => json_encode($configuration, JSON_THROW_ON_ERROR),
                    ]);
                }
            });

            DB::table('escalas_valorativas')->where('tipo', 'numerica')
                ->where(fn ($query) => $query->whereNull('decimales')
                    ->orWhere('decimales', '<>', SieeConfiguration::RESULT_DECIMALS))
                ->update(['decimales' => SieeConfiguration::RESULT_DECIMALS]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('La precisión histórica no se revierte automáticamente; restaura el respaldo anterior a esta migración.');
    }
};
