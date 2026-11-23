<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Matricula;
use App\Models\Academico\Nivel;
use App\Models\Academico\Periodo;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\OpaqueUrlToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EarlyPromotions
{
    public const TABLE = 'matricula_promociones_anticipadas';
    public const RECOMMEND = 'academico.matriculas.recomendar_promocion';
    public const DECIDE = 'academico.matriculas.decidir_promocion';

    private function firstPeriod(Matricula $enrollment): ?Periodo
    {
        $today = now(tenant()->zonaHorariaInstitucional())->toDateString();
        if ($enrollment->estado !== 'activa' || $enrollment->anoLectivo->estado !== 'en_curso') {
            return null;
        }
        return Periodo::where('ano_lectivo_id', $enrollment->ano_lectivo_id)->where('orden', 1)->where('estado', Periodo::ESTADO_ABIERTO)
            ->whereDate('fecha_inicio', '<=', $today)->whereDate('fecha_fin', '>=', $today)->first();
    }

    private function gradeNumber(?Grado $grade): ?int
    {
        if (! $grade || ! in_array($grade->nivel?->nivel_educativo, [Nivel::NIVEL_PRIMARIA, Nivel::NIVEL_SECUNDARIA, Nivel::NIVEL_MEDIA], true)) {
            return null;
        }
        $ordinals = ['primero' => 1, 'primer' => 1, 'segundo' => 2, 'tercero' => 3, 'tercer' => 3,
            'cuarto' => 4, 'quinto' => 5, 'sexto' => 6, 'septimo' => 7, 'octavo' => 8, 'noveno' => 9,
            'decimo' => 10, 'undecimo' => 11, 'once' => 11, 'decimo primero' => 11];
        foreach ([$grade->nombre, $grade->codigo] as $label) {
            $name = Str::lower(Str::ascii(str_replace(['º', '°'], '', (string) $label)));
            $name = trim(str_replace(['grado', '.'], '', $name));
            if (isset($ordinals[$name])) {
                return $ordinals[$name];
            }
            if (preg_match('/^0?([1-9]|1[01])(?:ro|do|to|mo|vo|o|er)?$/D', $name, $matches)) {
                return (int) $matches[1];
            }
        }
        return null;
    }

    private function destinations(Matricula $enrollment): Builder
    {
        return Grupo::with('grado.nivel')->where('ano_lectivo_id', $enrollment->ano_lectivo_id)
            ->where('sede_id', $enrollment->grupo?->sede_id)->where('estado', 'activo')
            ->whereHas('grado', fn ($q) => $q->where('estado', 'activo'));
    }

    private function nextGrade(Matricula $enrollment, Grupo $group): bool
    {
        $current = $this->gradeNumber($enrollment->grupo?->grado);
        return $current !== null && $this->gradeNumber($group->grado) === $current + 1;
    }

    public function details(Matricula $enrollment, User $actor): ?array
    {
        $seeEvidence = $actor->canAny([self::RECOMMEND, self::DECIDE]);
        $issue = $actor->can(EarlyPromotionCertificates::ISSUE);
        if (! $seeEvidence && ! $issue) {
            return null;
        }
        $period = $this->firstPeriod($enrollment);
        $records = DB::table(self::TABLE.' as p')->leftJoin(EarlyPromotionCertificates::TABLE.' as c', 'c.promocion_id', '=', 'p.id')
            ->where('p.matricula_id', $enrollment->id)->when(! $seeEvidence, fn ($q) => $q->where('p.estado', 'aprobada'))
            ->orderByDesc('p.id')->get(['p.token', 'p.origen', 'p.destino', 'p.estado', 'p.fecha_decision', 'p.acta_numero',
                'p.decidido_por', 'c.consecutivo', 'c.firmante', 'c.fecha_expedicion', 'c.codigo_verificacion_hash', 'c.copia_verificable_path', ...($seeEvidence ? [
                    'p.recomendacion', 'p.fecha_consentimiento', 'p.consentimiento_nombre', 'p.fecha_recomendacion', 'p.recomendado_por', 'p.acta_nombre',
                ] : [])]);
        foreach ($records as $record) {
            $record->constancia = $record->consecutivo ? ['numero' => EarlyPromotionCertificates::number($record->consecutivo),
                'firmante' => $record->firmante, 'fecha_expedicion' => $record->fecha_expedicion,
                'verificable' => (bool) $record->codigo_verificacion_hash, 'con_anexo' => (bool) $record->copia_verificable_path] : null;
            unset($record->consecutivo, $record->firmante, $record->fecha_expedicion, $record->codigo_verificacion_hash, $record->copia_verificable_path);
        }
        if (! $seeEvidence && $records->isEmpty()) {
            return null;
        }
        $canRecommend = $period && $actor->can(self::RECOMMEND) && ! $records->contains('estado', 'recomendada');
        $targets = $canRecommend ? $this->destinations($enrollment)->orderBy('nombre')->get()
            ->filter(fn ($group) => $this->nextGrade($enrollment, $group))->values()
            ->map(fn ($group) => ['token' => OpaqueUrlToken::for('grupo', $group->id), 'nombre' => $group->grado->nombre.' / '.$group->nombre]) : [];
        return ['expedientes' => $records, 'destinos' => $targets, 'puede_recomendar' => $canRecommend,
            'firmante_previsto' => $issue ? $actor->name : null,
            'puede_decidir' => $actor->can(self::DECIDE), 'primer_periodo_abierto' => (bool) $period,
            'inicio_periodo' => $period?->fecha_inicio->toDateString(), 'hoy' => now(tenant()->zonaHorariaInstitucional())->toDateString()];
    }

    private function lockEnrollment(Matricula $candidate): Matricula
    {
        $year = AnoLectivo::lockForUpdate()->findOrFail($candidate->ano_lectivo_id);
        return Matricula::lockForUpdate()->findOrFail($candidate->id)->setRelation('anoLectivo', $year);
    }

    private function fingerprint(User $actor, array $fields, UploadedFile $file): string
    {
        return hash('sha256', json_encode([$actor->id, $fields, $file->getClientOriginalName(),
            hash_file('sha256', $file->getRealPath())], JSON_THROW_ON_ERROR));
    }

    private function store(UploadedFile $file, Matricula $enrollment, string $token, User $actor, string $field): StoredFile
    {
        $folder = 'matriculas/trayectoria/'.OpaqueUrlToken::for('matricula', $enrollment->id).'/promociones/'.$token.'/'.$field;
        return EnrollmentEvidenceFile::store($file, $folder, $actor, $field);
    }

    public function recommend(Matricula $candidate, User $actor, array $data): void
    {
        abort_unless($actor->can(self::RECOMMEND), 403);
        DB::transaction(function () use ($candidate, $actor, $data) {
            $enrollment = $this->lockEnrollment($candidate);
            $cases = DB::table(self::TABLE)->where('matricula_id', $enrollment->id);
            $hash = $this->fingerprint($actor, [$data['grupo_token'], (int) $data['version'], $data['recomendacion'],
                $data['fecha_consentimiento'], $data['fecha_recomendacion']], $data['consentimiento']);
            if ((clone $cases)->where('huella', $hash)->exists()) {
                return;
            }
            abort_if((clone $cases)->where('estado', 'recomendada')->exists(), 409, 'Ya hay una recomendación pendiente de decisión.');
            $period = $this->firstPeriod($enrollment);
            $last = DB::table('matricula_movimientos')->where('matricula_id', $enrollment->id)->orderByDesc('version')->first();
            abort_unless((int) $data['version'] === (int) ($last->version ?? 0), 409, 'La trayectoria cambió. Actualiza la ficha.');
            $date = $data['fecha_recomendacion'];
            abort_unless($period && $date >= $period->fecha_inicio->toDateString()
                && $date <= now(tenant()->zonaHorariaInstitucional())->toDateString()
                && $date >= ($last->fecha_efectiva ?? $enrollment->created_at?->timezone(tenant()->zonaHorariaInstitucional())->toDateString()),
                422, 'Registra la recomendación durante el primer periodo abierto y con la matrícula vigente.');
            $group = OpaqueUrlToken::find('grupo', $data['grupo_token'], $this->destinations($enrollment));
            abort_unless($group, 404);
            abort_unless($this->nextGrade($enrollment, $group), 422, 'Selecciona el grado inmediatamente superior.');
            $token = Str::random(24);
            $file = $this->store($data['consentimiento'], $enrollment, $token, $actor, 'consentimiento');
            $cases->insert(['matricula_id' => $enrollment->id, 'token' => $token, 'periodo_id' => $period->id,
                'grupo_destino_id' => $group->id, 'version_origen' => $data['version'], 'estado' => 'recomendada',
                'origen' => $enrollment->grupo->grado->nombre.' / '.$enrollment->grupo->nombre, 'destino' => $group->grado->nombre.' / '.$group->nombre,
                'fecha_consentimiento' => $data['fecha_consentimiento'], 'consentimiento_token' => $file->public_token,
                'consentimiento_nombre' => $file->original_name, 'fecha_recomendacion' => $date, 'recomendacion' => $data['recomendacion'],
                'recomendador_id' => $actor->id, 'recomendado_por' => $actor->name, 'registrado_en' => now(), 'huella' => $hash]);
            AuditLogger::tenant($actor, 'CREATE', 'promocion_anticipada', $token, null, ['estado' => 'recomendada'], $data['recomendacion']);
        });
    }

    public function decide(Matricula $candidate, string $token, User $actor, array $data): void
    {
        abort_unless($actor->can(self::DECIDE), 403);
        DB::transaction(function () use ($candidate, $actor, $token, $data) {
            $enrollment = $this->lockEnrollment($candidate);
            $query = DB::table(self::TABLE)->where('matricula_id', $enrollment->id)->where('token', $token);
            $case = (clone $query)->lockForUpdate()->firstOrFail();
            $decision = $data['decision'];
            $date = $data['fecha_decision'];
            $hash = $this->fingerprint($actor, [$decision, $date, $data['acta_numero']], $data['acta']);
            if ($case->estado !== 'recomendada') {
                abort_unless(hash_equals($case->huella_decision, $hash), 409, 'La decisión ya quedó registrada.');
                return;
            }
            abort_if($date < $case->fecha_recomendacion || $date > now(tenant()->zonaHorariaInstitucional())->toDateString(),
                422, 'La fecha del acta debe estar entre la recomendación y hoy.');
            $movement = null;
            if ($decision === 'aprobada') {
                $period = $this->firstPeriod($enrollment);
                abort_unless($period && $period->id === $case->periodo_id && $date >= $period->fecha_inicio->toDateString(),
                    422, 'La aprobación debe registrarse en el primer periodo abierto.');
                $version = DB::table('matricula_movimientos')->where('matricula_id', $enrollment->id)->max('version');
                abort_unless((int) $version === $case->version_origen, 409, 'El grupo de origen cambió después de la recomendación.');
                $group = $this->destinations($enrollment)->whereKey($case->grupo_destino_id)->first();
                abort_unless($group && $this->nextGrade($enrollment, $group), 422, 'El destino ya no corresponde al siguiente grado activo.');
                $movement = app(EnrollmentTrajectory::class)->applyPromotion($enrollment, $actor, [
                    'grupo_token' => OpaqueUrlToken::for('grupo', $group->id), 'version' => $case->version_origen,
                    'fecha_efectiva' => $date, 'motivo' => 'Promoción anticipada. Acta '.$data['acta_numero'].' del Consejo Directivo.']);
            }
            $file = $this->store($data['acta'], $enrollment, $token, $actor, 'acta');
            $query->update(['estado' => $decision, 'fecha_decision' => $date, 'acta_numero' => $data['acta_numero'],
                'acta_token' => $file->public_token, 'acta_nombre' => $file->original_name, 'movimiento_id' => $movement,
                'revisor_id' => $actor->id, 'decidido_por' => $actor->name, 'decidido_en' => now(), 'huella_decision' => $hash]);
            AuditLogger::tenant($actor, 'UPDATE', 'promocion_anticipada', $token, ['estado' => 'recomendada'],
                ['estado' => $decision, 'fecha' => $date, 'acta' => $data['acta_numero']], $case->recomendacion);
        });
    }
}
