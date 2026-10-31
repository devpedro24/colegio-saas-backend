<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\BoletinPlantilla;
use App\Models\Academico\DatosInstitucionales;
use App\Models\Academico\EscalaValorativa;
use App\Models\User;
use App\Services\AcademicPlanAccess;
use App\Services\BulletinArchiveSync;
use App\Services\BulletinPerformance;
use App\Services\BulletinTemplateDesign;
use App\Services\TenantOnboarding;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use App\Support\Storage\StoredFilePublicToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Design management only. This controller never emits or approves a report. */
final class BoletinPlantillaController extends Controller
{
    public function index(Request $request, AcademicPlanAccess $plan): JsonResponse
    {
        $this->mayRead($request);
        $request->validate(['ambito' => 'sometimes|in:periodo,final', 'publicadas' => 'sometimes|boolean']);
        $query = BoletinPlantilla::query()->when($request->filled('ambito'), fn ($q) => $q->where('boletin_plantillas.ambito', $request->input('ambito')));
        if ($request->boolean('publicadas')) {
            $query->join('boletin_plantilla_revisiones as revision', function ($join): void {
                $join->on('revision.plantilla_id', '=', 'boletin_plantillas.id')
                    ->on('revision.revision', '=', 'boletin_plantillas.revision_publicada');
            })->select('boletin_plantillas.*', 'revision.nombre as seleccion_nombre', 'revision.configuracion as seleccion_configuracion');
        }
        $page = $query->orderByDesc('boletin_plantillas.updated_at')->orderByDesc('boletin_plantillas.id')->paginate(20);

        return response()->json(['data' => [
            'colegio' => (string) tenant('name'), 'puede_editar' => $this->canEdit($request, $plan),
            'institucion' => $this->institution(),
            'plantillas' => $page->getCollection()->map(fn (BoletinPlantilla $item) => $this->present($item)),
            'pagina' => $page->currentPage(), 'ultima_pagina' => $page->lastPage(), 'total' => $page->total(),
        ]]);
    }

    public function show(Request $request, string $token): JsonResponse
    {
        $this->mayRead($request);

        return response()->json(['data' => $this->present($this->find($token))]);
    }

    private function institution(): array
    {
        $item = DatosInstitucionales::query()->first();
        $year = AnoLectivo::where('estado', 'en_curso')->orderByDesc('id')->first();
        $scale = $year ? EscalaValorativa::where('ano_lectivo_id', $year->id)
            ->where('tipo', 'numerica')->find($year->siee['escala_id'] ?? null) : null;
        $ranges = $scale?->desempenos ?? [];
        $path = TenantOnboarding::logoReadPath();
        $logo = Storage::disk('tenant')->exists($path) ? Storage::disk('tenant')->get($path) : null;

        return ['name' => $item?->nombre ?: (string) tenant('name'), 'nit' => $item?->nit ?? '',
            'dane' => $item?->codigo_dane ?? '', 'resolution' => $item?->resolucion_men ?? '',
            'address' => $item?->direccion ?? '', 'phone' => $item?->telefono ?? '', 'email' => $item?->correo ?? '',
            'principal' => User::whereHas('roles', fn ($query) => $query->where('name', 'rector'))->where('status', 'active')->orderBy('id')->value('name') ?? '',
            'logo' => $logo ? 'data:image/png;base64,'.base64_encode($logo) : '',
            'year' => $year?->nombre ?? '', 'periods' => $year?->num_periodos ?? 4,
            'annual' => (bool) ($year?->periodo_sumatorio ?? false),
            'performanceRanges' => $ranges, 'scaleLegend' => implode("\n", BulletinPerformance::legend($ranges))];
    }

    public function revisions(Request $request, string $token): JsonResponse
    {
        $this->mayRead($request);
        $item = $this->find($token);
        $rows = DB::table('boletin_plantilla_revisiones')->where('plantilla_id', $item->id)
            ->orderByDesc('revision')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($row) => [
            'revision' => (int) $row->revision, 'nombre' => $row->nombre, 'ambito' => $row->ambito,
            'configuracion' => json_decode($row->configuracion, true, flags: JSON_THROW_ON_ERROR),
            'publicado_en' => CarbonImmutable::parse($row->published_at, 'UTC')->toIso8601String(),
        ])])->header('Cache-Control', 'private, no-store');
    }

    public function uploadAsset(Request $request, AcademicPlanAccess $plan): JsonResponse
    {
        $this->mayEdit($request, $plan);
        $data = $request->validate(['archivo' => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/webp',
            'max:2048', 'dimensions:max_width=3000,max_height=3000']]);
        try {
            $file = app(StorageService::class)->store($data['archivo'], 'bulletin-templates/assets', (string) $request->user()->email,
                maxBytes: 2 * 1024 * 1024);
        } catch (StorageException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }
        AuditLogger::tenant($request->user(), 'CREATE', 'boletin_plantilla_archivo', (string) $file->id,
            null, ['bytes' => $file->size, 'mime' => $file->mime]);

        return response()->json(['data' => ['token' => StoredFilePublicToken::for($file),
            'bytes' => $file->size, 'mime' => $file->mime]], 201)->header('Cache-Control', 'private, no-store');
    }

    public function asset(Request $request, string $token): JsonResponse
    {
        $this->mayRead($request);
        $file = StoredFilePublicToken::findForTenant((string) tenant()->getKey(), $token);
        abort_unless($file && str_contains($file->path, '/bulletin-templates/assets/'), 404);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return response()->json(['data' => ['content' => 'data:'.$file->mime.';base64,'.base64_encode(Storage::disk($file->disk)->get($file->path))]])
            ->header('Cache-Control', 'private, no-store');
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
            if ($locked->wasChanged('nombre')) {
                app(BulletinArchiveSync::class)->forTemplate($locked, $request->user());
            }
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
            if ($locked->wasChanged('nombre')) {
                app(BulletinArchiveSync::class)->forTemplate($locked, $request->user());
            }
            AuditLogger::tenant($request->user(), 'PUBLISH', 'boletin_plantilla', (string) $locked->id,
                ['version' => $version], ['version' => $version + 1, 'revision' => $revision]);
        });

        return response()->json(['data' => $this->present($item->fresh())]);
    }

    public function destroy(Request $request, AcademicPlanAccess $plan, string $token): JsonResponse
    {
        $this->mayEdit($request, $plan);
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $item = $this->find($token);
        DB::transaction(function () use ($request, $item, $version): void {
            $locked = BoletinPlantilla::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $version, 409, __('La plantilla cambió. Recarga antes de eliminar.'));
            $previous = ['nombre' => $locked->nombre, 'ambito' => $locked->ambito, 'version' => $version];
            $locked->update(['version' => $version + 1, 'updated_by' => $request->user()->id]);
            // Keep published revisions and issue foreign keys intact; issued PDFs use their frozen snapshot.
            $locked->delete();
            AuditLogger::tenant($request->user(), 'DELETE', 'boletin_plantilla', (string) $locked->id,
                $previous, ['eliminada' => true, 'version' => $version + 1]);
        });

        return response()->json(['data' => ['eliminada' => true]]);
    }

    private function find(string $token): BoletinPlantilla
    {
        return OpaqueUrlToken::find('boletin-plantilla', $token, BoletinPlantilla::query()) ?? abort(404);
    }

    private function present(BoletinPlantilla $item): array
    {
        return ['token' => OpaqueUrlToken::for('boletin-plantilla', $item->id),
            'nombre' => $item->seleccion_nombre ?? $item->nombre, 'ambito' => $item->ambito,
            'configuracion' => isset($item->seleccion_configuracion) ? json_decode($item->seleccion_configuracion, true, flags: JSON_THROW_ON_ERROR) : $item->configuracion,
            'version' => (int) $item->version,
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
