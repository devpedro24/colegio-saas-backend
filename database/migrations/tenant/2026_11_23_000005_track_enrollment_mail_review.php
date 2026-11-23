<?php

use App\Support\AcademicTokenIndex;
use App\Support\OpaqueUrlToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingreso_notificaciones', function (Blueprint $table) {
            $table->foreignId('solicitud_id')->nullable()->constrained('ingreso_solicitudes')->nullOnDelete();
            $table->string('tipo', 16)->nullable();
            $table->string('estado', 12)->default('pendiente');
            $table->unsignedInteger('intentos')->default(0);
            $table->timestampTz('intento_en')->nullable();
            $table->timestampTz('proximo_despacho_en')->nullable();
            $table->index(['solicitud_id', 'estado']);
            $table->index(['estado', 'intento_en']);
            $table->index(['estado', 'proximo_despacho_en']);
        });
        Schema::table('correo_revisiones', fn (Blueprint $t) => $t->string('tipo_aviso', 40)->nullable());
        DB::table('ingreso_notificaciones')->whereNotNull('enviada_en')->update(['estado' => 'enviado']);
        DB::table('ingreso_notificaciones')->select('id', 'clave', 'enviada_en')->chunkById(500, function ($rows) {
            $tokens = [];
            foreach ($rows as $row) {
                $tokens[] = ['resource' => 'ingreso-aviso', 'record_id' => $row->id, 'token' => OpaqueUrlToken::for('ingreso-aviso', $row->id)];
                if (preg_match('/^([0-9]+):(validacion|admision|grupo)(?::|$)/D', $row->clave, $m)
                    && DB::table('ingreso_solicitudes')->where('id', $m[1])->exists()) {
                    DB::table('ingreso_notificaciones')->where('id', $row->id)->update([
                        'solicitud_id' => $m[1], 'tipo' => $m[2],
                        // The old outbox never recorded attempts. A missing acknowledgment is ambiguous.
                        'estado' => $row->enviada_en ? 'enviado' : 'en_revision',
                    ]);
                }
            }
            DB::table(AcademicTokenIndex::TABLE)->upsert($tokens, ['resource', 'record_id'], ['token']);
        });
    }

    public function down(): void
    {
        DB::table(AcademicTokenIndex::TABLE)->where('resource', 'ingreso-aviso')->delete();
        Schema::table('correo_revisiones', fn (Blueprint $t) => $t->dropColumn('tipo_aviso'));
        Schema::table('ingreso_notificaciones', fn (Blueprint $t) => $t->dropColumn([
            'solicitud_id', 'tipo', 'estado', 'intentos', 'intento_en', 'proximo_despacho_en',
        ]));
    }
};
