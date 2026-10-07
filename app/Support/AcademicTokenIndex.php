<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Durable reverse index inside each tenant DB; never an authorization cache. */
final class AcademicTokenIndex
{
    public const TABLE = 'academic_public_tokens';

    public const RESOURCES = [
        'anos_lectivos' => ['ano-lectivo'], 'periodos' => ['periodo'],
        'sedes' => ['sede'], 'jornadas' => ['jornada'], 'niveles' => ['nivel'],
        'grados' => ['grado'], 'grupos' => ['grupo'], 'bloques_horarios' => ['bloque-horario'],
        'espacios_fisicos' => ['espacio-fisico'], 'areas' => ['area'], 'materias' => ['materia'],
        'asignaciones_docentes' => ['asignacion-docente'], 'sesiones_horario' => ['sesion-horario'],
        'matriculas' => ['matricula'], 'componentes_evaluacion' => ['componente-evaluacion'],
        'actividades_evaluacion' => ['actividad-evaluacion'], 'eventos' => ['evento'],
        'recuperaciones_academicas' => ['recuperacion-academica'],
        'promociones_academicas' => ['promocion-academica'],
        'preinformes' => ['preinforme'],
        'asistencia_solicitudes' => ['asistencia-solicitud'],
        'aulas' => ['aula'], 'aula_secciones' => ['aula-seccion'],
        'aula_recursos' => ['aula-recurso'], 'aula_entregas' => ['aula-entrega'],
        'aula_intentos' => ['aula-intento'], 'aula_preguntas' => ['aula-pregunta'],
        'aula_adjuntos' => ['aula-adjunto'],
        'aula_pregunta_medios' => ['aula-pregunta-medio'],
        'aula_respuesta_medios' => ['aula-respuesta-medio'],
        'escalas_valorativas' => ['escala-valorativa'], 'escala_opciones' => ['escala-opcion'], 'metodos_aprobacion' => ['metodo-aprobacion'],
        'modelos_pedagogicos' => ['modelo-pedagogico'], 'users' => ['usuario', 'docente'],
    ];

    private array $ready = [];

    public function created(Model $model): void
    {
        if (! tenancy()->initialized || ! isset(self::RESOURCES[$model->getTable()])
            || $model->getConnection()->getName() !== DB::connection()->getName()) {
            return;
        }
        $connection = $model->getConnection();
        $database = $connection->getName().'|'.$connection->getDatabaseName();
        // Earlier migrations can create rows before this index has been installed.
        // Remember only positive checks, so a later migration can enable it.
        if (! isset($this->ready[$database])) {
            if (! $connection->getSchemaBuilder()->hasTable(self::TABLE)) {
                return;
            }
            $this->ready[$database] = true;
        }
        $rows = [];
        foreach (self::RESOURCES[$model->getTable()] as $resource) {
            $rows[] = ['resource' => $resource, 'record_id' => $model->getKey(),
                'token' => OpaqueUrlToken::for($resource, $model->getKey())];
        }
        $connection->table(self::TABLE)->upsert($rows, ['resource', 'record_id'], ['token']);
    }

    /** Rebuild after a raw SQL import or APP_KEY rotation, in bounded batches. */
    public function rebuild(): int
    {
        $count = 0;
        foreach (self::RESOURCES as $table => $resources) {
            // El índice se instala antes de algunas tablas académicas futuras.
            // Un colegio nuevo debe poder migrar desde cero en orden cronológico.
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            DB::table($table)->select('id')->orderBy('id')->chunkById(500, function ($records) use ($resources, &$count): void {
                $rows = [];
                foreach ($records as $record) {
                    foreach ($resources as $resource) {
                        $rows[] = ['resource' => $resource, 'record_id' => $record->id,
                            'token' => OpaqueUrlToken::for($resource, $record->id)];
                    }
                }
                DB::table(self::TABLE)->upsert($rows, ['resource', 'record_id'], ['token']);
                $count += count($rows);
            });
        }

        return $count;
    }
}
