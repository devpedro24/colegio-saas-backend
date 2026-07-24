<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de PLANES (membresias SaaS) en la BD CENTRAL.
 *
 * El superadministrador crea/edita planes; cada colegio (tenants.plan) referencia
 * un plan por su `key`. Las features vienen del catalogo cerrado App\Plans\PlanCatalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();              // esencial | estandar | premium | <slug>
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            // Precios (pendientes de estudio comercial -> nullable).
            $table->decimal('price_monthly', 12, 2)->nullable();
            $table->decimal('price_annual', 12, 2)->nullable();

            // Limites cuantitativos. null = ilimitado.
            $table->integer('max_estudiantes')->nullable();
            $table->integer('storage_gb')->nullable();
            $table->integer('max_sedes')->nullable();
            $table->integer('max_pasarelas')->nullable();

            // Features activas (subconjunto de PlanCatalog::featureKeys()).
            $table->json('features')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
