<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ingreso_campanas')->select(['id', 'configuracion'])->orderBy('id')->chunkById(100, function ($campaigns): void {
            foreach ($campaigns as $campaign) {
                $config = json_decode((string) $campaign->configuracion, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($config) || ! empty($config['pin_expira_en'])) {
                    continue;
                }

                // Older campaigns without a deadline mean "no expiration". Keep
                // their existing PIN hash and private-link lookup unchanged.
                $config['pin_expira_en'] = null;
                DB::table('ingreso_campanas')->where('id', $campaign->id)->update([
                    'configuracion' => json_encode($config, JSON_THROW_ON_ERROR),
                ]);
                DB::table('ingreso_solicitudes')->where('campana_id', $campaign->id)->update(['pin_expira' => null]);
            }
        });
    }

    public function down(): void
    {
        // The old 15-minute deadlines cannot be reconstructed safely.
    }
};
