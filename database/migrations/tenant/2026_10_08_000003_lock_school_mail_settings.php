<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correo_configuracion', function (Blueprint $t) {
            $t->string('revision', 32)->default('');
            $t->string('ultima_autorizacion', 24)->nullable();
            $t->timestamp('desconectado_en')->nullable();
            $t->text('app_password')->nullable()->change();
        });
        // Only add a revision; preserve the existing encrypted credential byte-for-byte.
        foreach (DB::table('correo_configuracion')->pluck('key') as $key) {
            DB::table('correo_configuracion')->where('key', $key)->update(['revision' => Str::random(32)]);
        }
    }

    public function down(): void
    {
        // Keep nullable secret: disconnecting deliberately destroys the saved credential.
        Schema::table('correo_configuracion', fn (Blueprint $t) => $t->dropColumn(['revision', 'ultima_autorizacion', 'desconectado_en']));
    }
};
