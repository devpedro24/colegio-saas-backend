<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_mail_change_requests', function (Blueprint $t) {
            $t->id();
            $t->string('url_token', 24)->unique();
            $t->string('tenant_id');
            $t->string('requester_id');
            $t->string('requester_name');
            $t->string('revision', 32);
            $t->string('accion', 16);
            $t->text('motivo');
            $t->string('estado', 16)->default('pendiente');
            $t->string('reviewer_id')->nullable();
            $t->text('observacion')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['estado', 'id']);
            $t->index(['tenant_id', 'revision', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_mail_change_requests');
    }
};
