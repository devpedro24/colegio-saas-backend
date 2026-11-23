<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\Periodo;
use App\Services\EscalaVisualService;
use App\Services\SieeConfiguration;
use App\Support\AcademicDecimal;
use App\Support\OpaqueUrlToken;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EnrollmentGradeContext
{
    public function subjects(Matricula $enrollment): Builder
    {
        return Materia::where('ano_lectivo_id', $enrollment->ano_lectivo_id)->where('estado', 'activo')
            ->whereIn('id', DB::table('materias_curriculares')->where('ano_lectivo_id', $enrollment->ano_lectivo_id)
                ->where('grado_id', $enrollment->grupo->grado_id)->select('materia_id'));
    }

    public function scale(Matricula $enrollment): array
    {
        $config = app(SieeConfiguration::class)->resolve($enrollment->anoLectivo);
        $numeric = EscalaValorativa::findOrFail($config['escala_id']);
        $method = MetodoAprobacion::findOrFail($config['metodo_id']);
        $visual = app(EscalaVisualService::class)->forGroup($enrollment->grupo);
        $snapshot = ['nombre' => $numeric->nombre, 'minimo' => $config['valor_min'], 'maximo' => $config['valor_max'],
            'grado' => $enrollment->grupo->grado->nombre, 'grupo' => $enrollment->grupo->nombre,
            'grupo_token' => OpaqueUrlToken::for('grupo', $enrollment->grupo_id),
            'metodo' => $method->only('calculo_nota', 'ambito', 'nota_minima'), 'desempenos' => $numeric->desempenos,
            'reglas' => Arr::only($config, array_diff(array_keys(SieeConfiguration::DEFAULTS), ['escala_id', 'metodo_id'])),
            'decimales' => $config['decimales'], 'escala_visual' => $visual?->nombre,
            'opciones' => $visual?->opciones->map(fn ($o) => ['token' => OpaqueUrlToken::for('escala-opcion', $o->id),
                'nombre' => $o->nombre, 'valor' => (string) $o->valor_equivalente])->values()->all() ?? []];

        return [...$snapshot, 'version' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    public function form(Matricula $enrollment): array
    {
        $writable = $enrollment->estado === 'activa' && ! $enrollment->anoLectivo->estaCerrado();
        $scale = null;
        $scaleError = null;
        try {
            $scale = $this->scale($enrollment);
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 422) {
                throw $error;
            }
            $scaleError = $error->getMessage();
        }

        return [
            'editable' => $writable,
            'escala' => $scale, 'error_escala' => $scaleError, 'hoy' => now(tenant()->zonaHorariaInstitucional())->toDateString(),
            'max_bytes' => (int) config('storage.max_file_bytes'),
            'materias' => $this->subjects($enrollment)->orderBy('nombre')->get()->map(fn ($m) => [
                'token' => OpaqueUrlToken::for('materia', $m->id), 'nombre' => $m->nombre]),
            'periodos' => Periodo::where('ano_lectivo_id', $enrollment->ano_lectivo_id)->orderBy('orden')->get()->map(fn ($p) => [
                'token' => OpaqueUrlToken::for('periodo', $p->id), 'nombre' => $p->nombre, 'orden' => $p->orden,
                'desde' => $p->fecha_inicio->toDateString(), 'hasta' => $p->fecha_fin->toDateString()]),
        ];
    }

    /** Serialize competing decisions and uploads against a placement or year closure. */
    public function lockEnrollment(Matricula $enrollment): Matricula
    {
        $year = AnoLectivo::lockForUpdate()->findOrFail($enrollment->ano_lectivo_id);
        $locked = Matricula::lockForUpdate()->findOrFail($enrollment->id);
        abort_unless($locked->estado === 'activa' && ! $year->estaCerrado(), 422, 'Se requiere una matrícula activa en un año abierto.');

        return $locked->setRelation('anoLectivo', $year);
    }

    public function entryDate(Matricula $enrollment): ?string
    {
        return DB::table(CertifiedExternalGrades::CERTIFICATES)->where('matricula_id', $enrollment->id)->value('fecha_ingreso')
            ?? DB::table(PendingCertificates::TABLE)->where('matricula_id', $enrollment->id)->value('fecha_ingreso')
            ?? DB::table(EntryAssessments::TABLE)->where('matricula_id', $enrollment->id)->whereNull('resultado_traslado_id')
                ->whereNotNull('fecha_ingreso')->value('fecha_ingreso');
    }

    public function grade(array $data, array $scale): array
    {
        $choice = null;
        if ($scale['opciones']) {
            $choice = collect($scale['opciones'])->firstWhere('token', $data['opcion_token'] ?? '');
            abort_unless($choice && !isset($data['valor']), 422, 'Selecciona una categoría vigente de la escala del grado.');
            $value = $choice['valor'];
        } else {
            abort_unless(isset($data['valor']) && !isset($data['opcion_token']), 422, 'Indica el valor reconocido en la escala del colegio.');
            $value = $data['valor'];
            abort_if(BigDecimal::of($value)->strippedOfTrailingZeros()->getScale() > $scale['decimales'], 422,
                'La definitiva excede la precisión permitida por el SIEE.');
        }
        $exact = BigDecimal::of($value);
        abort_if($exact->isLessThan($scale['minimo']) || $exact->isGreaterThan($scale['maximo']), 422, 'El valor reconocido está fuera de la escala del colegio.');
        return ['valor' => AcademicDecimal::normalize($value), 'categoria' => $choice['nombre'] ?? null];
    }
}
