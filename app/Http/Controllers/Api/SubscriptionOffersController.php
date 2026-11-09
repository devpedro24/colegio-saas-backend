<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Plans\PlanCatalog;
use App\Plans\PlanPricing;
use App\Services\SchoolSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Authenticated, read-only comparison. The school comes only from the session/host. */
final class SubscriptionOffersController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $selection = $request->validate(['students' => ['sometimes', 'integer', 'min:30', 'max:100000'],
            'sites' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $school = tenant() ? SchoolSubscription::owner(tenant()) : null;
        $contract = $school?->commercial;
        $effective = $school ? SchoolSubscription::effective($school) : null;
        $pricingPlan = $effective ? Plan::where('key', $effective->key)->firstOrFail() : null;
        $current = $effective ? ['key' => $effective->key, 'name' => $effective->name,
            'customized' => (bool) ($contract['customized'] ?? false),
            'features' => $effective->features,
            'quote' => is_array($contract) ? ($contract['quote'] ?? PlanPricing::quote($pricingPlan, $contract)) : PlanPricing::quote($pricingPlan, [])] : null;
        if ($current && $selection) {
            $simulation = [...($contract ?? []), ...$selection];
            $simulation['addons'] = array_values(array_diff($simulation['addons'] ?? [], $pricingPlan->features ?? []));
            $current['quote'] = PlanPricing::quote($pricingPlan, $simulation);
        }
        $offers = Plan::whereIn('key', ['estandar', 'premium'])->where('is_active', true)->orderBy('sort_order')->get()
            ->map(static function (Plan $plan) use ($selection, $contract, $current): array {
                $quote = $current && $current['key'] === $plan->key && ! $current['customized']
                    ? $current['quote'] : PlanPricing::quote($plan, [
                        'students' => $selection['students'] ?? $contract['students'] ?? 30,
                        'sites' => $selection['sites'] ?? $contract['sites'] ?? 1,
                    ]);
                return ['key' => $plan->key, 'name' => $plan->name, 'description' => $plan->description,
                    'features' => $plan->features ?? [], 'pricing' => PlanPricing::rules($plan),
                    'storage_gb' => $plan->storage_gb, 'quote' => $quote];
            })->all();

        $students = $selection['students'] ?? $contract['students'] ?? 30;
        $sites = $selection['sites'] ?? $contract['sites'] ?? 1;
        return response()->json(['data' => $offers, 'current' => $current,
            'comparison' => ['max_students' => 1000, 'max_sites' => 5, 'premium_after_students' => 400,
                'requires_proposal' => $students > 1000 || $sites > 5],
            'catalog' => ['features' => PlanCatalog::features()]])->header('Cache-Control', 'private, no-store');
    }
}
