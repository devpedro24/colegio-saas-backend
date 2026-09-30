<?php

declare(strict_types=1);

use App\Support\AcademicTokenIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(AcademicTokenIndex::TABLE, function (Blueprint $table): void {
            $table->string('resource', 40);
            $table->unsignedBigInteger('record_id');
            $table->string('token', 24);
            $table->primary(['resource', 'record_id']);
            $table->unique(['resource', 'token']);
        });
        if (tenancy()->initialized) {
            app(AcademicTokenIndex::class)->rebuild();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(AcademicTokenIndex::TABLE);
    }
};
