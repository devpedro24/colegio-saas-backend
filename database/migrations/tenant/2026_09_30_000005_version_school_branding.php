<?php

declare(strict_types=1);

use App\Services\TenantOnboarding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('datos_institucionales', fn (Blueprint $table) => $table->string('logo_version', 64)->nullable());
        if (tenancy()->initialized && Storage::disk('tenant')->exists(TenantOnboarding::logoPath())) {
            DB::table('datos_institucionales')->update([
                'logo_version' => hash_file('sha256', Storage::disk('tenant')->path(TenantOnboarding::logoPath())),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('datos_institucionales', fn (Blueprint $table) => $table->dropColumn('logo_version'));
    }
};
