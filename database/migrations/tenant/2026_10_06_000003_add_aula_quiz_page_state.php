<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('aula_intentos', function (Blueprint $table): void {
            $table->unsignedInteger('pagina_actual')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('aula_intentos', fn (Blueprint $table) => $table->dropColumn('pagina_actual'));
    }
};
