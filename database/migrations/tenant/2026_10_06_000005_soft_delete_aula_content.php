<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('aula_secciones', 'deleted_at')) {
            Schema::table('aula_secciones', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
        if (! Schema::hasColumn('aula_recursos', 'deleted_at')) {
            Schema::table('aula_recursos', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
        if (! Schema::hasColumn('aula_recursos', 'deleted_with_section')) {
            Schema::table('aula_recursos', function (Blueprint $table): void {
                $table->boolean('deleted_with_section')->default(false);
            });
        }
    }

    public function down(): void
    {
        // El historial eliminado se conserva incluso si se revierte el despliegue.
    }
};
