<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boletin_plantillas', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 120);
            $table->string('ambito', 12); // periodo | final
            $table->json('configuracion');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('revision_publicada')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['ambito', 'updated_at']);
        });
        Schema::create('boletin_plantilla_revisiones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plantilla_id')->constrained('boletin_plantillas')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('nombre', 120);
            $table->string('ambito', 12);
            $table->json('configuracion');
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->unique(['plantilla_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boletin_plantilla_revisiones');
        Schema::dropIfExists('boletin_plantillas');
    }
};
