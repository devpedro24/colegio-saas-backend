<?php

declare(strict_types=1);

namespace App\Plans;

use App\Models\Plan;
use Illuminate\Validation\ValidationException;

/** Central-only commercial rules. All amounts are integer Colombian pesos. */
final class PlanPricing
{
    public static function defaults(string $key): array
    {
        return ['currency' => 'COP', 'student_monthly' => $key === 'premium' ? 2500 : 1500,
            'minimum_students' => 30, 'included_sites' => $key === 'premium' ? 3 : 1,
            'additional_site_monthly' => 15000,
            'addons' => ['asistencia' => 200, 'preinformes' => 200,
                'boletines_personalizables' => 300, 'aula' => 300,
                'soporte_prioritario' => 100]];
    }

    public static function rules(Plan $plan): array
    {
        return array_replace(self::defaults($plan->key), $plan->pricing ?? []);
    }

    public static function quote(Plan $plan, array $contract): array
    {
        $rules = self::rules($plan);
        if ($contract['customized'] ?? false) {
            if ($plan->key !== 'estandar') {
                throw ValidationException::withMessages(['subscription.customized' => __('El contrato personalizado utiliza Estándar como base.')]);
            }
            $override = $contract['pricing_override'] ?? [];
            foreach (['student_monthly', 'included_sites', 'additional_site_monthly'] as $field) {
                if (isset($override[$field])) {
                    $amount = $override[$field];
                    if (! is_int($amount) || $amount < ($field === 'included_sites' ? 1 : 0)
                        || $amount > ($field === 'included_sites' ? 100 : 10000000)) {
                        throw ValidationException::withMessages(['subscription.pricing_override.'.$field => __('validation.numeric', ['attribute' => $field])]);
                    }
                    $rules[$field] = $amount;
                }
            }
        }
        $students = max($rules['minimum_students'], (int) ($contract['students'] ?? $rules['minimum_students']));
        $sites = max(1, (int) ($contract['sites'] ?? 1));
        $addons = array_values(array_unique($contract['addons'] ?? []));
        $unit = $rules['student_monthly'];
        $lines = [];
        foreach ($addons as $key) {
            if (! array_key_exists($key, $rules['addons']) || in_array($key, $plan->features ?? [], true)
                || ! PlanCatalog::available($key)) {
                throw ValidationException::withMessages(['subscription.addons' => __('El complemento no está disponible para este plan.')]);
            }
            $unit += $rules['addons'][$key];
            $lines[] = ['key' => $key, 'unit' => $rules['addons'][$key], 'quantity' => $students,
                'total' => $rules['addons'][$key] * $students];
        }
        $extraSites = max(0, $sites - $rules['included_sites']);
        $subtotal = $students * $unit + $extraSites * $rules['additional_site_monthly'];
        $discount = (float) ($contract['discount_percent'] ?? 0);
        if (! is_finite($discount) || $discount < 0 || $discount > 100) {
            throw ValidationException::withMessages(['subscription.discount_percent' => __('validation.between.numeric', ['attribute' => 'discount_percent', 'min' => 0, 'max' => 100])]);
        }
        // Integer pesos, with a percentage precise to two decimals.
        $discount = round($discount, 2);
        $discountTotal = (int) round($subtotal * $discount / 100, 0, PHP_ROUND_HALF_UP);

        return ['currency' => 'COP', 'students' => $students, 'sites' => $sites, 'addons' => $addons,
            'minimum_students' => $rules['minimum_students'], 'included_sites' => $rules['included_sites'],
            'base_student_monthly' => $rules['student_monthly'], 'student_monthly' => $unit,
            'students_total' => $students * $rules['student_monthly'], 'addon_lines' => $lines,
            'additional_sites' => $extraSites, 'additional_site_monthly' => $rules['additional_site_monthly'],
            'sites_total' => $extraSites * $rules['additional_site_monthly'],
            'subtotal' => $subtotal, 'discount_percent' => $discount, 'discount_total' => $discountTotal,
            'monthly_total' => $subtotal - $discountTotal,
            'annual_reference' => 12 * ($subtotal - $discountTotal)];
    }
}
