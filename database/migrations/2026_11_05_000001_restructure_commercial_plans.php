<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Plans\PlanCatalog;
use App\Plans\PlanPricing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', fn (Blueprint $table) => $table->json('pricing')->nullable());
        foreach (PlanCatalog::defaultPlans() as $data) {
            Plan::updateOrCreate(['key' => $data['key']], [...$data, 'is_active' => true,
                'pricing' => PlanPricing::defaults($data['key'])]);
        }
        DB::table('plans')->whereNotIn('key', ['estandar', 'premium'])->update(['is_active' => false]);
        DB::table('tenants')->where('plan', 'esencial')->update(['plan' => 'estandar']);
        // Existing data and contracts are retained; no invoices or payments are generated.
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('pricing'));
    }
};
