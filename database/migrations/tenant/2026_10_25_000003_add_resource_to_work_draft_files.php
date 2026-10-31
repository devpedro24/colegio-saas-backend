<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_draft_files', fn (Blueprint $table) => $table->unsignedBigInteger('resource_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('work_draft_files', fn (Blueprint $table) => $table->dropColumn('resource_id'));
    }
};
