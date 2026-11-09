<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_records', function (Blueprint $table) {
            $table->string('url_token', 24)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('variant', 16);
            $table->string('event', 80);
            $table->string('subject', 120)->default('');
            $table->text('message')->nullable();
            $table->timestampTz('created_at');
            $table->index(['user_id', 'created_at', 'url_token'], 'notification_records_owner_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_records');
    }
};
