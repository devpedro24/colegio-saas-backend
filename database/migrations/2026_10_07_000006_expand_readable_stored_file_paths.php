<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stored_files', function (Blueprint $table): void {
            $table->dropUnique('stored_files_tenant_id_checksum_unique');
            $table->string('path', 512)->change();
            $table->unique(['tenant_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::table('stored_files', function (Blueprint $table): void {
            $table->dropUnique('stored_files_tenant_id_path_unique');
            $table->string('path')->change();
            $table->unique(['tenant_id', 'checksum']);
        });
    }
};
