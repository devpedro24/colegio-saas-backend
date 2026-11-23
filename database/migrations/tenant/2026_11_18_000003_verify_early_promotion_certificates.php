<?php

use App\Services\Ingreso\EarlyPromotionCertificates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(EarlyPromotionCertificates::TABLE, function (Blueprint $t) {
            $t->char('codigo_verificacion_hash', 64)->nullable()->unique();
            $t->string('emisor_verificacion')->nullable();
            $t->string('copia_verificable_path')->nullable();
            $t->char('copia_verificable_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table(EarlyPromotionCertificates::TABLE)->whereNotNull('codigo_verificacion_hash')->exists()) {
            throw new RuntimeException('Hay códigos vigentes en constancias distribuidas.');
        }
        Schema::table(EarlyPromotionCertificates::TABLE, fn (Blueprint $t) => $t->dropColumn([
            'codigo_verificacion_hash', 'emisor_verificacion', 'copia_verificable_path', 'copia_verificable_sha256',
        ]));
    }
};
