<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary(); // UUID inmutable (RN-MT: clave interna)

            // Identidad del colegio
            $table->string('name');                       // Nombre comercial del colegio
            $table->string('slug')->unique();             // Subdominio legible <slug>.<dominio>
            $table->string('legal_name')->nullable();     // Razon social
            $table->string('nit')->nullable();            // NIT (Colombia)

            // Comercial y ciclo de vida
            $table->string('plan')->default('esencial');          // esencial | estandar | premium
            $table->string('status')->default('provisioning');    // maquina de estados del tenant

            // Configuracion base
            $table->char('calendar', 1)->default('A');            // Calendario A o B
            $table->string('locale')->default('es-CO');           // i18n (RN-RG-420)
            $table->string('timezone')->default('America/Bogota');

            $table->timestamps();
            $table->json('data')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
