<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('url_token', 24)->unique();
            $table->string('reference', 16)->unique();
            $table->string('tenant_id')->index();
            $table->string('school_name');
            $table->string('origin_name');
            $table->string('requester_name');
            $table->unsignedBigInteger('requester_id');
            $table->string('request_key', 24);
            $table->string('subject', 160);
            $table->string('category', 24);
            $table->string('status', 24)->default('abierto');
            $table->boolean('priority')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'request_key']);
            $table->index(['status', 'priority', 'updated_at']);
        });
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets');
            $table->string('tenant_id')->index();
            $table->string('request_key', 24);
            $table->string('author_side', 16);
            $table->string('author_name');
            $table->unsignedBigInteger('author_id');
            $table->text('body');
            $table->timestamps();
            $table->unique(['ticket_id', 'request_key']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
