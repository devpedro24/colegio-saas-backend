<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\DatosInstitucionales;
use App\Models\Academico\Matricula;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Storage\ReadableStorageName;
use App\Support\Storage\StorageService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class EarlyPromotionCertificates
{
    public const TABLE = 'matricula_constancias_promocion';
    public const ISSUE = 'academico.matriculas.emitir_constancia_promocion';

    public static function number(int $serial): string
    {
        return sprintf('PA-%06d', $serial);
    }

    public function issue(Matricula $enrollment, string $caseToken, User $actor): void
    {
        abort_unless($actor->can(self::ISSUE), 403);
        DB::transaction(function () use ($enrollment, $caseToken, $actor) {
            $case = DB::table(EarlyPromotions::TABLE)->where('matricula_id', $enrollment->id)
                ->where('token', $caseToken)->lockForUpdate()->firstOrFail();
            abort_unless($case->estado === 'aprobada' && $case->movimiento_id && $case->acta_token, 422,
                'Solo se puede emitir la constancia de una promoción aprobada con acta.');
            $school = DatosInstitucionales::query()->value('nombre') ?: tenant('name');
            $existing = DB::table(self::TABLE)->where('promocion_id', $case->id)->first();
            if ($existing) {
                if (! $existing->codigo_verificacion_hash) {
                    $original = $this->readArchive($existing->pdf_path, $existing->pdf_sha256);
                    $verification = CertificateVerification::create();
                    $number = self::number($existing->consecutivo);
                    $copy = CertificateVerification::appendToArchive($original, $verification, $number, $school);
                    $path = dirname($existing->pdf_path).'/'.$number.'-verificable.pdf';
                    $this->saveArchive($path, $copy);
                    DB::table(self::TABLE)->where('consecutivo', $existing->consecutivo)->update([
                        'codigo_verificacion_hash' => $verification['hash'], 'emisor_verificacion' => $school,
                        'copia_verificable_path' => $path, 'copia_verificable_sha256' => hash('sha256', $copy),
                    ]);
                    AuditLogger::tenant($actor, 'UPDATE', 'constancia_promocion', $caseToken, null, ['numero' => $number, 'verificacion_habilitada' => true]);
                }
                return;
            }
            $now = now(tenant()->zonaHorariaInstitucional());
            $serial = DB::table(self::TABLE)->insertGetId(['promocion_id' => $case->id, 'firmante_id' => $actor->id,
                'firmante' => $actor->name, 'fecha_expedicion' => $now->toDateString(), 'emitido_en' => $now], 'consecutivo');
            $number = self::number($serial);
            $verification = CertificateVerification::create();
            $year = $enrollment->anoLectivo->nombre;
            $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false,
                'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
            $document = new Dompdf($options);
            $document->setPaper('A4');
            $document->loadHtml(view('pdf.early-promotion', [
                'number' => $number, 'school' => $school, 'student' => $enrollment->estudiante->name, 'year' => $year,
                'origin' => $case->origen, 'destination' => $case->destino, 'minutes' => $case->acta_numero,
                'decisionDate' => \Carbon\CarbonImmutable::parse($case->fecha_decision)->format('d/m/Y'),
                'issueDate' => $now->format('d/m/Y'), 'signer' => $actor->name, 'verification' => $verification,
            ])->render(), 'UTF-8');
            $document->render();
            $pdf = $document->output();
            $path = StorageService::tenantFolder(tenant()).'/anos-lectivos/'.ReadableStorageName::segment($year).
                '/matriculas/constancias-promocion/'.$number.'.pdf';
            $this->saveArchive($path, $pdf);
            $hash = hash('sha256', $pdf);
            DB::table(self::TABLE)->where('consecutivo', $serial)->update(['pdf_path' => $path, 'pdf_sha256' => $hash,
                'codigo_verificacion_hash' => $verification['hash'], 'emisor_verificacion' => $school]);
            AuditLogger::tenant($actor, 'CREATE', 'constancia_promocion', $caseToken, null,
                ['numero' => $number, 'acta' => $case->acta_numero, 'firmante' => $actor->name, 'sha256' => $hash]);
        });
    }

    private function saveArchive(string $path, string $pdf): void
    {
        $disk = Storage::disk('tenant');
        abort_if($disk->exists($path), 409, 'El archivo de este consecutivo ya existe.');
        DB::afterRollBack(fn () => $disk->delete($path));
        abort_unless($disk->put($path, $pdf), 500, 'No se pudo guardar la constancia.');
    }

    private function readArchive(string $path, string $hash): string
    {
        $bytes = Storage::disk('tenant')->get($path);
        abort_unless($bytes !== null && hash_equals($hash, hash('sha256', $bytes)), 409,
            'La constancia archivada no está disponible para descarga.');
        return $bytes;
    }

    public function download(Matricula $enrollment, string $caseToken, User $actor, bool $copy = false)
    {
        abort_unless($actor->canAny([self::ISSUE, EarlyPromotions::DECIDE, EarlyPromotions::RECOMMEND]), 403);
        $issue = DB::table(self::TABLE.' as c')->join(EarlyPromotions::TABLE.' as p', 'p.id', '=', 'c.promocion_id')
            ->where('p.matricula_id', $enrollment->id)->where('p.token', $caseToken)->where('p.estado', 'aprobada')->firstOrFail(['c.*']);
        abort_if($copy && ! $issue->copia_verificable_path, 404);
        $bytes = $copy ? $this->readArchive($issue->copia_verificable_path, $issue->copia_verificable_sha256)
            : $this->readArchive($issue->pdf_path, $issue->pdf_sha256);
        $number = self::number($issue->consecutivo);
        AuditLogger::tenant($actor, 'READ', 'constancia_promocion', $caseToken, null, ['numero' => $number]);
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'attachment; filename="constancia-'.$number.($copy ? '-verificable' : '').'.pdf"']);
    }
}
