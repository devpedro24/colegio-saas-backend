<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Models\Academico\BoletinEmision;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Services\BulletinIssueService;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Throwable;

/** Emisión individual; la publicación, no la preparación ni aprobación, habilita descarga al estudiante. */
final class BoletinEmisionController extends Controller
{
    public function index(Request $request, string $matriculaToken): JsonResponse
    {
        $enrollment = $this->enrollment($matriculaToken);
        $staff = $request->user()->can('boletines.emisiones.ver');
        abort_unless($staff || $enrollment->estudiante_id === $request->user()->id, 403);
        $rows = BoletinEmision::where('matricula_id', $enrollment->id)
            ->when(! $staff, fn ($query) => $query->where('estado', 'publicado'))
            ->orderByDesc('id')->paginate(20);

        return response()->json(['data' => $rows->getCollection()->map(fn (BoletinEmision $row) => $this->present($row)),
            'pagina' => $rows->currentPage(), 'ultima_pagina' => $rows->lastPage(), 'total' => $rows->total()]);
    }

    public function preview(Request $request, BulletinIssueService $service, string $matriculaToken): JsonResponse
    {
        $this->permit($request, 'preparar');
        $data = $this->selection($request);
        $enrollment = $this->enrollment($matriculaToken);
        [$context, $period] = $service->context($enrollment, $data['contexto'], $data['periodo_token'] ?? null);
        $template = $service->template($data['contexto'], $data['plantilla_token'] ?? null);
        $document = $service->build($enrollment, $data['contexto'], $period, $template);

        return response()->json(['data' => ['contexto' => $context, 'huella_insumos' => $document['huella'],
            'plantilla' => $template['nombre'], 'resumen' => ['estudiante' => $document['snapshot']['estudiante'],
                'asignaturas' => count($document['snapshot']['asignaturas']),
                'indicadores' => count($document['snapshot']['indicadores']),
                'observacion' => $document['snapshot']['observacion'] !== null]]]);
    }

    public function prepare(Request $request, BulletinIssueService $service, string $matriculaToken): JsonResponse
    {
        $this->permit($request, 'preparar');
        $data = $this->selection($request);
        $enrollment = $this->enrollment($matriculaToken);
        $issue = DB::transaction(function () use ($request, $service, $enrollment, $data): BoletinEmision {
            Matricula::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            [$context, $period] = $service->context($enrollment, $data['contexto'], $data['periodo_token'] ?? null);
            $template = $service->template($data['contexto'], $data['plantilla_token'] ?? null);
            $document = $service->build($enrollment->fresh(), $data['contexto'], $period, $template);
            $previous = BoletinEmision::where('matricula_id', $enrollment->id)->where('contexto', $context)
                ->orderByDesc('revision')->lockForUpdate()->first();
            if ($previous && hash_equals($previous->huella_insumos, $document['huella'])
                && in_array($previous->estado, ['preparado', 'aprobado', 'publicado'], true)) {
                return $previous;
            }
            if ($previous && $previous->estado === 'preparado') {
                $previous->update(['estado' => 'descartado']);
            }
            $row = BoletinEmision::create([
                'matricula_id' => $enrollment->id, 'contexto' => $context, 'periodo_id' => $period?->id,
                'revision' => ($previous?->revision ?? 0) + 1, 'estado' => 'preparado',
                'plantilla_id' => $template['id'], 'plantilla_revision' => $template['revision'],
                'snapshot' => $document['snapshot'], 'huella_insumos' => $document['huella'],
                'prepared_by' => $request->user()->id,
            ]);
            AuditLogger::tenant($request->user(), 'PREPARE', 'boletin_emision', (string) $row->id,
                null, ['contexto' => $context, 'revision' => $row->revision]);

            return $row;
        });

        return response()->json(['data' => $this->present($issue)]);
    }

    public function approve(Request $request, BulletinIssueService $service, string $issueToken): JsonResponse
    {
        $this->permit($request, 'aprobar');
        $issue = $this->issue($issueToken);
        $path = null;
        try {
            $issue = DB::transaction(function () use ($request, $service, $issue, &$path): BoletinEmision {
                Matricula::whereKey($issue->matricula_id)->lockForUpdate()->firstOrFail();
                $locked = BoletinEmision::whereKey($issue->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->estado === 'preparado', 409, 'Solo una preparación pendiente puede aprobarse.');
                $this->assertLatest($locked);
                $current = $this->rebuild($service, $locked);
                abort_unless(hash_equals($locked->huella_insumos, $current['huella']), 409,
                    'Los insumos cambiaron. Prepara una nueva revisión antes de aprobar.');
                $pdf = $service->render($locked->snapshot, $current['logo']);
                $path = StorageService::tenantFolder(tenant()).'/boletines/'.Str::uuid().'.pdf';
                abort_unless(Storage::disk('tenant')->put($path, $pdf), 500, 'No se pudo guardar el PDF privado.');
                $locked->update(['estado' => 'aprobado', 'pdf_path' => $path, 'pdf_sha256' => hash('sha256', $pdf),
                    'approved_by' => $request->user()->id, 'issued_at' => now()]);
                AuditLogger::tenant($request->user(), 'APPROVE', 'boletin_emision', (string) $locked->id,
                    ['estado' => 'preparado'], ['estado' => 'aprobado', 'sha256' => $locked->pdf_sha256]);

                return $locked;
            });
        } catch (Throwable $error) {
            if ($path) {
                Storage::disk('tenant')->delete($path);
            }
            throw $error;
        }

        return response()->json(['data' => $this->present($issue)]);
    }

    public function publish(Request $request, BulletinIssueService $service, string $issueToken): JsonResponse
    {
        $this->permit($request, 'publicar');
        $issue = $this->issue($issueToken);
        $issue = DB::transaction(function () use ($request, $service, $issue): BoletinEmision {
            Matricula::whereKey($issue->matricula_id)->lockForUpdate()->firstOrFail();
            $locked = BoletinEmision::whereKey($issue->id)->lockForUpdate()->firstOrFail();
            if ($locked->estado === 'publicado') {
                return $locked;
            }
            abort_unless($locked->estado === 'aprobado', 409, 'Solo un boletín aprobado puede publicarse.');
            $this->assertLatest($locked);
            $this->verifiedPdf($locked);
            $current = $this->rebuild($service, $locked);
            abort_unless(hash_equals($locked->huella_insumos, $current['huella']), 409,
                'Los insumos cambiaron. Prepara y aprueba una nueva revisión.');
            BoletinEmision::where('matricula_id', $locked->matricula_id)->where('contexto', $locked->contexto)
                ->where('estado', 'publicado')->update(['estado' => 'sustituido']);
            $locked->update(['estado' => 'publicado', 'published_by' => $request->user()->id,
                'published_at' => now()]);
            AuditLogger::tenant($request->user(), 'PUBLISH', 'boletin_emision', (string) $locked->id,
                ['estado' => 'aprobado'], ['estado' => 'publicado', 'revision' => $locked->revision]);

            return $locked;
        });

        return response()->json(['data' => $this->present($issue)]);
    }

    public function pdf(Request $request, string $issueToken)
    {
        $issue = $this->issue($issueToken);
        $staff = $request->user()->can('boletines.emisiones.ver');
        $owner = Matricula::whereKey($issue->matricula_id)->where('estudiante_id', $request->user()->id)->exists();
        abort_unless($staff || ($owner && $issue->estado === 'publicado'), 403);
        abort_unless(in_array($issue->estado, ['aprobado', 'publicado', 'sustituido'], true), 404);
        $content = $this->verifiedPdf($issue);
        AuditLogger::tenant($request->user(), 'READ', 'boletin_emision', (string) $issue->id,
            null, ['tipo' => 'pdf']);

        return response($content, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="boletin-academico.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function selection(Request $request): array
    {
        return $request->validate(['contexto' => ['required', Rule::in(['periodo', 'final'])],
            'periodo_token' => ['required_if:contexto,periodo', 'prohibited_if:contexto,final', 'string'],
            'plantilla_token' => ['nullable', 'string']]);
    }

    private function rebuild(BulletinIssueService $service, BoletinEmision $issue): array
    {
        $enrollment = Matricula::findOrFail($issue->matricula_id);
        $period = $issue->periodo_id ? Periodo::findOrFail($issue->periodo_id) : null;
        $scope = $period ? 'periodo' : 'final';
        $templateToken = $issue->plantilla_id
            ? OpaqueUrlToken::for('boletin-plantilla', $issue->plantilla_id) : null;
        $template = $service->template($scope, $templateToken, $issue->plantilla_revision);

        return $service->build($enrollment, $scope, $period, $template);
    }

    private function verifiedPdf(BoletinEmision $issue): string
    {
        abort_unless($issue->pdf_path && $issue->pdf_sha256
            && Storage::disk('tenant')->exists($issue->pdf_path), 409, 'El PDF archivado no está disponible.');
        $bytes = Storage::disk('tenant')->get($issue->pdf_path);
        abort_unless(hash_equals($issue->pdf_sha256, hash('sha256', $bytes)), 409,
            'La integridad del PDF archivado no coincide.');

        return $bytes;
    }

    private function assertLatest(BoletinEmision $issue): void
    {
        abort_unless(! BoletinEmision::where('matricula_id', $issue->matricula_id)
            ->where('contexto', $issue->contexto)->where('revision', '>', $issue->revision)->exists(), 409,
            'Existe una revisión más reciente del boletín.');
    }

    private function issue(string $token): BoletinEmision
    {
        return OpaqueUrlToken::find('boletin-emision', $token, BoletinEmision::query()) ?? abort(404);
    }

    private function enrollment(string $token): Matricula
    {
        return OpaqueUrlToken::find('matricula', $token, Matricula::query()) ?? abort(404);
    }

    private function permit(Request $request, string $action): void
    {
        abort_unless($request->user()->can('boletines.emisiones.'.$action), 403);
    }

    private function present(BoletinEmision $issue): array
    {
        return ['token' => OpaqueUrlToken::for('boletin-emision', $issue->id),
            'matricula_token' => OpaqueUrlToken::for('matricula', $issue->matricula_id),
            'contexto' => $issue->periodo_id ? 'periodo' : 'final',
            'periodo_token' => $issue->periodo_id ? OpaqueUrlToken::for('periodo', $issue->periodo_id) : null,
            'revision' => (int) $issue->revision, 'estado' => $issue->estado,
            'plantilla' => $issue->snapshot['plantilla']['nombre'] ?? null,
            'preparado_en' => $issue->created_at?->toIso8601String(),
            'aprobado_en' => $issue->issued_at?->toIso8601String(),
            'publicado_en' => $issue->published_at?->toIso8601String(),
            'pdf_disponible' => (bool) $issue->pdf_sha256];
    }
}
