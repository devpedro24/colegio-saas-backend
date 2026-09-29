<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('periodos_sumatorios_legado')->get() as $archive) {
            if (DB::table('audit_logs')->where('accion', 'MIGRATE')
                ->where('recurso', 'periodo_sumatorio')->where('recurso_id', (string) $archive->ano_lectivo_id)->exists()) {
                continue;
            }
            $year = DB::table('anos_lectivos')->find($archive->ano_lectivo_id);
            $periods = DB::table('periodos')->where('ano_lectivo_id', $archive->ano_lectivo_id)->orderBy('orden')->get();
            DB::table('audit_logs')->insert([
                'actor_id' => null,
                'actor_email' => null,
                'actor_rol' => 'sistema',
                'accion' => 'MIGRATE',
                'recurso' => 'periodo_sumatorio',
                'recurso_id' => (string) $archive->ano_lectivo_id,
                'valor_previo' => json_encode(['ano' => json_decode($archive->ano_anterior, true),
                    'periodos' => json_decode($archive->periodos_anteriores, true)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'valor_nuevo' => json_encode(['ano' => $year, 'periodos' => $periods], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'motivo' => 'Conversión del período adicional legado en resultado anual calculado. Original archivado en periodos_sumatorios_legado.',
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // La auditoría es append-only: no se eliminan evidencias al revertir código.
    }
};
