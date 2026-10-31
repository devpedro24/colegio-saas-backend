<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Solo se corrigen los valores técnicos originales; una edición del superadministrador se conserva. */
    private const LABELS = [
        'academico.anos.gestionar' => ['anos.gestionar', 'Gestionar años lectivos'],
        'academico.anos.transicionar' => ['anos.iniciar_cerrar', 'Iniciar o cerrar años lectivos'],
        'academico.periodos.transicionar' => ['periodos.abrir_cerrar_reabrir', 'Abrir, cerrar o reabrir períodos académicos'],
        'academico.configurar' => ['configurar', 'Configurar parámetros académicos'],
        'academico.estructura.gestionar' => ['estructura.gestionar', 'Gestionar la estructura organizacional'],
        'academico.plan_estudios.gestionar' => ['plan_estudios.gestionar', 'Gestionar el plan de estudios'],
        'academico.matriculas.gestionar' => ['matriculas.gestionar', 'Gestionar matrículas'],
        'eventos.gestionar' => ['eventos.gestionar_todos', 'Gestionar todos los eventos del colegio'],
        'eventos.configurar' => ['eventos.configurar', 'Configurar eventos'],
        'eventos.publicar_institucional' => ['eventos.publicar_institucional', 'Publicar eventos institucionales'],
        'eventos.publicar_asignados' => ['eventos.publicar_asignados', 'Publicar eventos para grupos asignados'],
    ];

    public function up(): void
    {
        foreach (self::LABELS as $key => [$legacy, $label]) {
            DB::table('rbac_permissions')->where('key', $key)->where('module', 'academico')
                ->update(['module' => 'Académico']);
            DB::table('rbac_permissions')->where('key', $key)->where('action', $legacy)
                ->update(['action' => $label]);
        }
    }

    public function down(): void
    {
        foreach (self::LABELS as $key => [$legacy, $label]) {
            DB::table('rbac_permissions')->where('key', $key)->where('action', $label)
                ->update(['action' => $legacy]);
            DB::table('rbac_permissions')->where('key', $key)->where('module', 'Académico')
                ->update(['module' => 'academico']);
        }
    }
};
