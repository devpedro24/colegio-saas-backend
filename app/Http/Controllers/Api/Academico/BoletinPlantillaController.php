<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\BoletinPlantilla;
use App\Services\AcademicPlanAccess;
use App\Services\BulletinTemplateDesign;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Design management only. This controller never emits or approves a report. */
final class BoletinPlantillaController extends Controller
{
    public function index(Request $request, AcademicPlanAccess $plan): JsonResponse
    {
        $this->mayRead($request);
        $page = BoletinPlantilla::query()->orderByDesc('updated_at')->orderByDesc('id')->paginate(20);

        return response()->json(['data' => [
            'colegio' => (string) tenant('name'), 'puede_editar' => $this->canEdit($request, $plan),
            'base' => ['periodo' => BulletinTemplateDesign::base('periodo'),
                'final' => BulletinTemplateDesign::base('final')],
            'plantillas' => $page->getCollection()->map(fn (BoletinPlantilla $item) => $this->present($item)),
            'pagina' => $page->currentPage(), 'ultima_pagina' => $page->lastPage(), 'total' => $page->total(),
        ]]);
    }

    public function show(Request $request, string $token): JsonResponse
    {
        $this->mayRead($request);

        return response()->json(['data' => $this->present($this->find($token))]);
    }

    public function create(Request $request, AcademicPlanAccess $plan): JsonResponse
    {
        $this->mayEdit($request, $plan);
        $data = BulletinTemplateDesign::validate($request->all());
        $actor = $request->user();
        $item = DB::transaction(function () use ($data, $actor): BoletinPlantilla {
            $item = BoletinPlantilla::create([
                'nombre' => $data['nombre'], 'ambito' => $data['ambito'],
                'configuracion' => $data['configuracion'], 'version' => 1,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            AuditLogger::tenant($actor, 'CREATE', 'boletin_plantilla', (string) $item->id,
                null, ['ambito' => $item->ambito, 'version' => 1]);

            return $item;
        });

        return response()->json(['data' => $this->present($item)], 201);
    }

    public function save(Request $request, AcademicPlanAccess $plan, string $token): JsonResponse
    {
        $this->mayEdit($request, $plan);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $data = BulletinTemplateDesign::validate($request->all());
        $item = $this->find($token);
        abort_unless($item->ambito === $data['ambito'], 422, 'No cambies el tipo de una plantilla existente.');
        DB::transaction(function () use ($request, $item, $version, $data): void {
            $locked = BoletinPlantilla::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $version, 409, 'La plantilla cambió. Recarga antes de guardar.');
            $locked->update(['nombre' => $data['nombre'], 'configuracion' => $data['configuracion'],
                'version' => $version + 1, 'updated_by' => $request->user()->id]);
            AuditLogger::tenant($request->user(), 'UPDATE', 'boletin_plantilla', (string) $locked->id,
                ['version' => $version], ['version' => $version + 1]);
        });

        return response()->json(['data' => $this->present($item->fresh())]);
    }

    public function publish(Request $request, AcademicPlanAccess $plan, string $token): JsonResponse
    {
        $this->mayEdit($request, $plan);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $data = BulletinTemplateDesign::validate($request->all());
        $item = $this->find($token);
        abort_unless($item->ambito === $data['ambito'], 422, 'No cambies el tipo de una plantilla existente.');
        DB::transaction(function () use ($request, $item, $version, $data): void {
            $locked = BoletinPlantilla::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $version, 409, 'La plantilla cambió. Recarga antes de publicar.');
            $revision = ((int) $locked->revision_publicada) + 1;
            DB::table('boletin_plantilla_revisiones')->insert([
                'plantilla_id' => $locked->id, 'revision' => $revision,
                'nombre' => $data['nombre'], 'ambito' => $locked->ambito,
                'configuracion' => json_encode($data['configuracion'], JSON_THROW_ON_ERROR),
                'published_by' => $request->user()->id, 'published_at' => now(),
            ]);
            $locked->update(['nombre' => $data['nombre'], 'configuracion' => $data['configuracion'],
                'version' => $version + 1, 'revision_publicada' => $revision,
                'updated_by' => $request->user()->id]);
            AuditLogger::tenant($request->user(), 'PUBLISH', 'boletin_plantilla', (string) $locked->id,
                ['version' => $version], ['version' => $version + 1, 'revision' => $revision]);
        });

        return response()->json(['data' => $this->present($item->fresh())]);
    }

    private function find(string $token): BoletinPlantilla
    {
        return OpaqueUrlToken::find('boletin-plantilla', $token, BoletinPlantilla::query()) ?? abort(404);
    }

    private function present(BoletinPlantilla $item): array
    {
        return ['token' => OpaqueUrlToken::for('boletin-plantilla', $item->id),
            'nombre' => $item->nombre, 'ambito' => $item->ambito,
            'configuracion' => $item->configuracion, 'version' => (int) $item->version,
            'revision_publicada' => $item->revision_publicada === null ? null : (int) $item->revision_publicada,
            'actualizado_en' => $item->updated_at?->toIso8601String()];
    }

    private function mayRead(Request $request): void
    {
        abort_unless($request->user()->can('boletines.plantillas.ver')
            || $request->user()->can('boletines.plantillas.gestionar'), 403);
    }

    private function canEdit(Request $request, AcademicPlanAccess $plan): bool
    {
        return $request->user()->can('boletines.plantillas.gestionar') && $plan->customBulletins();
    }

    private function mayEdit(Request $request, AcademicPlanAccess $plan): void
    {
        abort_unless($request->user()->can('boletines.plantillas.gestionar'), 403);
        $plan->requireCustomBulletins();
    }
}
