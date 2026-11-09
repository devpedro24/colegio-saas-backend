<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\SchoolSubscription;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;

final class UpgradeCommercialContracts extends Command
{
    protected $signature = 'plans:initialize-contracts {--apply : Persist reviewed quantities}';
    protected $description = 'Initialize commercial contracts using current students and sites; never collects payments.';

    public function handle(): int
    {
        foreach (Tenant::whereNull('parent_id')->whereNotIn('status', [Tenant::STATUS_DELETED, Tenant::STATUS_IN_RETENTION])->get() as $school) {
            if (is_array($school->commercial)) {
                continue;
            }
            $plan = Plan::where('key', $school->plan)->firstOrFail();
            $contract = SchoolSubscription::prepare($plan, ['students' => max(30, SchoolSubscription::studentCount($school)),
                'sites' => max(1, 1 + $school->sedes()->whereNotIn('status', [Tenant::STATUS_DELETED, Tenant::STATUS_IN_RETENTION])->count()), 'addons' => []], $school);
            $this->line($school->slug.': '.$contract['students'].' / '.$contract['sites'].' · COP '.$contract['quote']['monthly_total']);
            if ($this->option('apply')) {
                $school->update(['commercial' => $contract]);
                AuditLogger::platform(null, 'UPDATE', 'colegio.contrato', $school->getKey(), null, $contract, 'Inicialización de contrato comercial', $school->getKey());
            }
        }
        return self::SUCCESS;
    }
}
