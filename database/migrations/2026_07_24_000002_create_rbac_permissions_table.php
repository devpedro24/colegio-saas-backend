<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo CENTRAL de permisos (editable por el superadmin).
 *
 * Es la fuente de verdad del RBAC: reemplaza al catalogo hardcoded de
 * App\Rbac\PermissionMatrix. Cada colegio (tenant) siembra sus filas de spatie
 * a partir de este catalogo, filtrado por su plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbac_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // p.ej. notas.registrar_materia_asignada
            $table->string('module');                 // agrupador visual
            $table->string('action');                 // descripcion legible
            // Gating por plan: si esta seteada y el plan del colegio NO incluye
            // esta feature (PlanCatalog), el permiso queda bloqueado (candado + upsell).
            $table->string('feature_key')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false); // del catalogo base; no se borra
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_permissions');
    }
};
