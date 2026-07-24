<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo CENTRAL de roles del tenant (editable por el superadmin).
 * ROL-01 Superadministrador es de plataforma y NO vive aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbac_roles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // p.ej. rector, docente
            $table->string('label');
            $table->boolean('is_system')->default(false); // del catalogo base; no se borra
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_roles');
    }
};
