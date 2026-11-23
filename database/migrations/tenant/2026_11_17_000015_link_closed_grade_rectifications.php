<?php

use App\Services\Ingreso\ClosedGradeAuthorizations;
use App\Services\Ingreso\EntryAssessments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(ClosedGradeAuthorizations::TABLE, function (Blueprint $t) {
            $t->foreignId('rectifica_valoracion_id')->nullable()->constrained(EntryAssessments::TABLE)->restrictOnDelete();
            $t->foreignId('rectificacion_id')->nullable()->unique()->constrained(EntryAssessments::REVISIONS)->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table(ClosedGradeAuthorizations::TABLE)->whereNotNull('rectifica_valoracion_id')->exists()) {
            throw new RuntimeException('Existen autorizaciones de rectificación que forman parte del historial académico.');
        }
        Schema::table(ClosedGradeAuthorizations::TABLE, function (Blueprint $t) {
            $t->dropConstrainedForeignId('rectificacion_id');
            $t->dropConstrainedForeignId('rectifica_valoracion_id');
        });
    }
};
