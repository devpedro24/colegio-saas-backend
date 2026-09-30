<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anos_lectivos', fn (Blueprint $table) => $table->dropColumn('siee_version'));
    }

    public function down(): void
    {
        Schema::table('anos_lectivos', fn (Blueprint $table) => $table->unsignedInteger('siee_version')->default(1));
    }
};
