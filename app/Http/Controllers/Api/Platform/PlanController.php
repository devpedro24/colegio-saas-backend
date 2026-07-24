<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Events\PlatformDataChanged;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Plans\PlanCatalog;
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
    /** Lista los planes + el catalogo cerrado de features y limites. */
    public function index(): JsonResponse
    {
        $planes = Plan::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $plan) => $this->present($plan));

        return response()->json([
            'data' => $planes,
            'catalog' => $this->catalog(),
        ]);
    }

    /** Crea un plan nuevo. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePlan($request, null);

        $plan = Plan::create($data);

        PlatformDataChanged::dispatch('plans', 'created');

        return response()->json(['plan' => $this->present($plan)], 201);
    }

    /** Detalle de un plan. */
    public function show(int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);

        return response()->json(['plan' => $this->present($plan)]);
    }

    /** Actualiza un plan (nombre, descripcion, estado, precios, limites, features). */
    public function update(Request $request, int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);

        $data = $this->validatePlan($request, $plan->id);
        $plan->update($data);

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
            'key' => [
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
            'features' => ['array'],
            // Cada feature debe existir en el catalogo cerrado.
            'features.*' => ['string', Rule::in(PlanCatalog::featureKeys())],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Normaliza: features unicas; is_active default true al crear.
        $validated['features'] = array_values(array_unique($validated['features'] ?? []));
        $validated['is_active'] = $validated['is_active'] ?? true;

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
            'id' => $plan->id,
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
            'features' => $plan->features ?? [],
            'sort_order' => $plan->sort_order,
        ];
    }
}
