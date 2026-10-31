<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_draft_files', function (Blueprint $table) {
            $table->string('token', 24)->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('section_id');
            $table->string('stored_token', 24);
            $table->unsignedBigInteger('bytes');
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_draft_files');
    }
};
