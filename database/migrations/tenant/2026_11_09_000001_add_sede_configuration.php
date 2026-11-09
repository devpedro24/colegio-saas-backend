<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sedes', function (Blueprint $table) {
            $table->string('departamento', 2)->nullable();
            $table->string('municipio', 5)->nullable();
            $table->unsignedInteger('capacidad_maxima')->nullable();
            $table->unsignedBigInteger('coordinador_id')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('school_user_id')->nullable()->unique();
            $table->string('school_credential_version', 64)->nullable();
        });
        foreach (DB::table('sedes')->whereNotNull('coordinador_email')->get(['id', 'coordinador_email']) as $sede) {
            $coordinator = DB::table('users')->whereNull('deleted_at')->where('email', $sede->coordinador_email)
                ->whereIn('role', ['coord_academico', 'coord_convivencia', 'coord_combinado'])->value('id');
            if ($coordinator) {
                DB::table('sedes')->where('id', $sede->id)->update(['coordinador_id' => $coordinator]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['school_user_id', 'school_credential_version']));
        Schema::table('sedes', fn (Blueprint $table) => $table->dropColumn(['departamento', 'municipio', 'capacidad_maxima', 'coordinador_id']));
    }
};
