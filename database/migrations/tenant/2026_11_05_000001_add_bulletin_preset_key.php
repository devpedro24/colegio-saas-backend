<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boletin_plantillas', fn (Blueprint $table) => $table->string('preset_key', 60)->nullable());
    }

    public function down(): void
    {
        Schema::table('boletin_plantillas', fn (Blueprint $table) => $table->dropColumn('preset_key'));
    }
};
