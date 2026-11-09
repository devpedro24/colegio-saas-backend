<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TenantChanged;
use App\Models\Academico\Sede;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\PlanCatalog;
use App\Plans\PlanPricing;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SchoolSubscription
{
    public static function notifyChanged(Tenant $school): void
    {
        $owner = self::owner($school);
        foreach ([$owner->id, ...$owner->sedes()->pluck('id')->all()] as $id) {
            TenantChanged::dispatch((string) $id, 'plan');
        }
    }

    public static function studentCount(Tenant $school): int
    {
        $owner = self::owner($school);
        $emails = [];
        foreach ([$owner, ...$owner->sedes()->whereNotIn('status', [Tenant::STATUS_DELETED, Tenant::STATUS_IN_RETENTION])->get()->all()] as $site) {
            $site->run(function () use (&$emails): void {
                foreach (User::where('role', 'estudiante')->where('status', 'active')->pluck('email') as $email) {
                    $emails[strtolower($email)] = true;
                }
            });
        }

        return count($emails);
    }

    public static function ensureStudentCapacity(Tenant $school): void
    {
        $owner = self::owner($school);
        $site = $owner->run(fn () => Sede::where('tenant_id', $school->parent_id ? $school->id : null)->first());
        if ($site?->capacidad_maxima !== null) {
            $count = $school->run(fn () => User::where('role', 'estudiante')->where('status', 'active')->count());
            abort_if($count >= $site->capacidad_maxima, 422, 'Se alcanzó la capacidad asignada a esta sede.');
        }
        $contract = $owner->commercial;
        if (is_array($contract)) {
            abort_if(self::studentCount($school) >= $contract['students'], 422,
                __('Se alcanzó el cupo contratado de estudiantes del colegio.'));
        }
    }

    public static function validate(Request $request): ?array
    {
        if (! $request->exists('subscription')) {
            return null;
        }

        return $request->validate([
            'subscription' => ['required', 'array:students,sites,addons,discount_percent,customized,pricing_override'],
            'subscription.customized' => ['sometimes', 'boolean'],
            'subscription.pricing_override' => ['sometimes', 'nullable', 'prohibited_unless:subscription.customized,true', 'array:student_monthly,included_sites,additional_site_monthly'],
            'subscription.pricing_override.student_monthly' => ['required_with:subscription.pricing_override', 'integer', 'min:0', 'max:10000000'],
            'subscription.pricing_override.included_sites' => ['required_with:subscription.pricing_override', 'integer', 'min:1', 'max:100'],
            'subscription.pricing_override.additional_site_monthly' => ['required_with:subscription.pricing_override', 'integer', 'min:0', 'max:10000000'],
            'subscription.discount_percent' => ['sometimes', 'numeric', 'between:0,100', 'decimal:0,2'],
            'subscription.students' => ['required', 'integer', 'min:30', 'max:100000'],
            'subscription.sites' => ['required', 'integer', 'min:1', 'max:100'],
            'subscription.addons' => ['present', 'array'],
            'subscription.addons.*' => ['string', 'distinct', Rule::in(array_keys(PlanPricing::defaults('estandar')['addons']))],
        ])['subscription'];
    }

    public static function owner(Tenant $tenant): Tenant
    {
        return $tenant->parent_id ? Tenant::findOrFail($tenant->parent_id) : $tenant;
    }

    /** An effective plan is a copy: a custom school never alters other subscribers. */
    public static function effective(Tenant $tenant): ?Plan
    {
        $owner = self::owner($tenant);
        $plan = Plan::where('key', $owner->plan)->first();
        if (! $plan) {
            return null;
        }
        $contract = $owner->commercial;
        if (! is_array($contract)) {
            $plan = clone $plan;
            $plan->features = array_values(array_filter($plan->features ?? [], [PlanCatalog::class, 'available']));

            return $plan;
        }
        $plan = clone $plan;
        $features = array_values(array_unique([...($plan->features ?? []), ...($contract['addons'] ?? [])]));
        if (($contract['sites'] ?? 1) > 1 || PlanPricing::rules($plan)['included_sites'] > 1
            || (($contract['customized'] ?? false) && ($contract['pricing_override']['included_sites'] ?? 1) > 1)) {
            $features[] = 'multi_sede';
        }
        if (in_array('aula', $features, true)) {
            $features[] = 'aula_colores';
        }
        $plan->features = array_values(array_filter(array_unique($features), [PlanCatalog::class, 'available']));
        $plan->max_estudiantes = $contract['students'];
        $plan->max_sedes = max(PlanPricing::rules($plan)['included_sites'], $contract['sites']);
        if ($contract['customized'] ?? false) {
            $plan->max_sedes = max($contract['pricing_override']['included_sites'] ?? 1, $contract['sites']);
            $plan->name = __('Personalizado');
        }

        return $plan;
    }

    public static function prepare(Plan $plan, ?array $contract, ?Tenant $school = null): array
    {
        $contract ??= $school?->commercial ?? ['students' => 30, 'sites' => 1, 'addons' => []];
        // When changing to Premium, remove upgrades now already included.
        $contract['addons'] = array_values(array_diff($contract['addons'] ?? [], $plan->features ?? []));
        $quote = PlanPricing::quote($plan, $contract);
        if ($school) {
            $allocated = $school->run(fn () => SedeCapacity::summary()['asignados']);
            abort_if($quote['students'] < $allocated, 422, 'El contrato debe cubrir los cupos asignados a las sedes.');
            $activeSites = 1 + $school->sedes()->whereNotIn('status', [Tenant::STATUS_DELETED, Tenant::STATUS_IN_RETENTION])->count();
            abort_if($quote['sites'] < $activeSites, 422, __('El contrato no puede dejar sedes existentes fuera de capacidad.'));
            abort_if($quote['students'] < self::studentCount($school), 422,
                __('El contrato no puede dejar estudiantes activos fuera de capacidad.'));
        }

        return ['students' => $quote['students'], 'sites' => $quote['sites'], 'addons' => $quote['addons'],
            'discount_percent' => $quote['discount_percent'],
            'customized' => (bool) ($contract['customized'] ?? false),
            'pricing_override' => ($contract['customized'] ?? false) ? ($contract['pricing_override'] ?? null) : null,
            'quote' => $quote, 'configured_at' => now()->toIso8601String()];
    }
}
