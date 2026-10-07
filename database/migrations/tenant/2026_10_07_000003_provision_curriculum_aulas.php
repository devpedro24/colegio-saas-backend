<?php

declare(strict_types=1);

use App\Services\AulaProvisioningService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        app(AulaProvisioningService::class)->syncAll();
    }

    public function down(): void
    {
        // Aulas may now contain teacher-authored content; backfill is not reversible.
    }
};
