<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_draft_vaults', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('scope', 24)->unique();
            $table->text('encrypted_key');
        });
        Schema::create('work_drafts', function (Blueprint $table) {
            $table->string('token', 24)->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedInteger('version');
            $table->string('iv', 24)->nullable();
            $table->mediumText('ciphertext')->nullable();
            $table->timestamp('edited_at');
            $table->timestamp('expires_at')->index();
            $table->boolean('discarded')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_drafts');
        Schema::dropIfExists('work_draft_vaults');
    }
};
