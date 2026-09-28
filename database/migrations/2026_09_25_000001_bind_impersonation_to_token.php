<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('impersonations', function (Blueprint $table) {
            $table->unsignedBigInteger('token_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('impersonations', fn (Blueprint $table) => $table->dropColumn('token_id'));
    }
};
