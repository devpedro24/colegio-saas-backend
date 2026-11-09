<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Events\PlatformDataChanged;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PaginatesRequests;
use App\Models\Plan;
use App\Models\Tenant;
use App\Plans\PlanCatalog;
use App\Plans\PlanPricing;
use App\Services\SchoolPlanSedeGuard;
use App\Services\SchoolSubscription;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestion de PLANES (membresias) desde el panel del superadministrador.
 * Rutas centrales (dominio de plataforma), protegidas por auth + platform.
 *
 * El catalogo de features/limites (PlanCatalog) es cerrado: se envia junto al
 * listado para que el frontend pinte los toggles sin inventar features.
 */
class PlanController extends Controller
{
    use PaginatesRequests;

    /** Lista los planes + el catalogo cerrado de features y limites. */
    public function index(Request $request): JsonResponse
    {
        $result = Plan::query()
            ->whereIn('key', ['estandar', 'premium'])->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($this->resolvePerPage($request))
            ->through(fn (Plan $plan) => $this->present($plan));

        $response = $this->paginatedResponse($result);
        $response->setData(array_merge(
            $response->getData(true),
            ['catalog' => $this->catalog()],
        ));

        return $response;
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', 'in:estandar,premium']]);
        $contract = SchoolSubscription::validate($request);
        abort_unless($contract, 422);
        $plan = Plan::where('key', $data['plan'])->where('is_active', true)->firstOrFail();

        return response()->json(['quote' => PlanPricing::quote($plan, $contract)])->header('Cache-Control', 'private, no-store');
    }

    /** The commercial catalog is closed; customization belongs to a school. */
    public function store(Request $request): JsonResponse
    {
        abort(422, __('Solo se comercializan Estándar y Premium. Personaliza el contrato del colegio.'));
    }

    /** Detalle de un plan. */
    public function show(string $key): JsonResponse
    {
        $plan = Plan::whereIn('key', ['estandar', 'premium'])->where('key', $key)->firstOrFail();

        return response()->json(['plan' => $this->present($plan)]);
    }

    /** Actualiza un plan (nombre, descripcion, estado, precios, limites, features). */
    public function update(Request $request, string $key): JsonResponse
    {
        $plan = Plan::whereIn('key', ['estandar', 'premium'])->where('key', $key)->firstOrFail();

        $data = $this->validatePlan($request, $plan->id);
        SchoolPlanSedeGuard::ensureSubscribersFit($plan->key, $data['features'],
            array_key_exists('max_sedes', $data) ? $data['max_sedes'] : $plan->max_sedes);
        $before = $this->present($plan);
        $plan->update($data);
        AuditLogger::platform($request->user(), 'UPDATE', 'plan', $plan->key, $before, $this->present($plan));
        foreach (Tenant::whereNull('parent_id')->where('plan', $plan->key)->cursor() as $school) {
            SchoolSubscription::notifyChanged($school);
        }

        PlatformDataChanged::dispatch('plans', 'updated');

        return response()->json(['plan' => $this->present($plan->fresh())]);
    }

    /**
     * Valida y normaliza el payload de un plan.
     *
     * @return array<string, mixed>
     */
    private function validatePlan(Request $request, ?int $ignoreId): array
    {
        $validated = $request->validate([
            'pricing' => ['sometimes', 'array:currency,student_monthly,minimum_students,included_sites,additional_site_monthly,addons'],
            'pricing.currency' => ['required_with:pricing', 'in:COP'],
            'pricing.student_monthly' => ['required_with:pricing', 'integer', 'min:0', 'max:10000000'],
            'pricing.minimum_students' => ['required_with:pricing', 'integer', 'min:30', 'max:100000'],
            'pricing.included_sites' => ['required_with:pricing', 'integer', 'min:1', 'max:100'],
            'pricing.additional_site_monthly' => ['required_with:pricing', 'integer', 'min:0', 'max:10000000'],
            'pricing.addons' => ['required_with:pricing', 'array:asistencia,preinformes,boletines_personalizables,aula,soporte_prioritario'],
            'pricing.addons.*' => ['integer', 'min:0', 'max:1000000'],
            'key' => [
                Rule::in(['estandar', 'premium']),
                'required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('plans', 'key')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'price_monthly' => ['nullable', 'numeric', 'min:0'],
            'price_annual' => ['nullable', 'numeric', 'min:0'],
            'max_estudiantes' => ['nullable', 'integer', 'min:1'],
            'storage_gb' => ['nullable', 'integer', 'min:1'],
            'max_sedes' => ['nullable', 'integer', 'min:1'],
            'max_pasarelas' => ['nullable', 'integer', 'min:1'],
            'max_roles_personalizados' => ['nullable', 'integer', 'min:0'],
            'features' => ['array'],
            // Cada feature debe existir en el catalogo cerrado.
            'features.*' => ['string', Rule::in(PlanCatalog::featureKeys())],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Normaliza: features unicas; is_active default true al crear.
        $validated['features'] = array_values(array_unique([
            ...PlanCatalog::requiredFeatureKeys(), ...($validated['features'] ?? []),
        ]));
        // The customization of Aula is part of Aula, never a separate product.
        $validated['features'] = array_values(array_diff($validated['features'], ['aula_colores']));
        if (in_array('aula', $validated['features'], true)) {
            $validated['features'][] = 'aula_colores';
        }
        $validated['is_active'] = $validated['is_active'] ?? true;
        if (! $request->exists('max_roles_personalizados')) {
            if ($ignoreId === null) {
                $validated['max_roles_personalizados'] = 0;
            } else {
                unset($validated['max_roles_personalizados']);
            }
        }

        return $validated;
    }

    /**
     * Catalogo cerrado de features (agrupadas por categoria) y limites.
     *
     * @return array<string, mixed>
     */
    private function catalog(): array
    {
        $categories = [];
        foreach (PlanCatalog::CATEGORIES as $key => $label) {
            $categories[] = ['key' => $key, 'label' => $label];
        }

        return [
            'categories' => $categories,
            'features' => PlanCatalog::features(),
            'limits' => PlanCatalog::limits(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Plan $plan): array
    {
        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'description' => $plan->description,
            'is_active' => $plan->is_active,
            'price_monthly' => $plan->price_monthly,
            'price_annual' => $plan->price_annual,
            'max_estudiantes' => $plan->max_estudiantes,
            'storage_gb' => $plan->storage_gb,
            'max_sedes' => $plan->max_sedes,
            'max_pasarelas' => $plan->max_pasarelas,
            'max_roles_personalizados' => $plan->max_roles_personalizados,
            'features' => $plan->features ?? [],
            'sort_order' => $plan->sort_order,
            'pricing' => PlanPricing::rules($plan),
        ];
    }
}
