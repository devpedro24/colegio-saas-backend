<?php

declare(strict_types=1);

namespace App\Rbac;

/**
 * Matriz de permisos (RBAC de 3 capas), fiel a la documentacion
 * (Arquitectura/_Globales/06 - Matriz de Permisos.md).
 *
 * Catalogo CERRADO: los roles y los permisos NO se crean desde el frontend;
 * el colegio solo puede activar/desactivar los permisos marcados como
 * "configurables" para cada rol. Las tres capas por celda:
 *
 *   - estructural (structural): otorgado y BLOQUEADO (no se puede quitar).
 *     El valor de la celda es el nivel de acceso (crud/editar/ver/reportar/
 *     aprobar/c/auto) que se muestra en la UI.
 *   - configurable (cfg / cfg_on): el colegio decide si lo permite. Default
 *     OFF salvo 'cfg_on'. Es lo unico editable desde el frontend.
 *   - denegado (denied): el rol no aparece en la celda -> sin permiso, bloqueado.
 *
 * NOTA: este es un subconjunto representativo de la matriz (7 modulos) para
 * validar el modelo end-to-end. Ampliar = agregar mas entradas a PERMISSIONS.
 */
class PermissionMatrix
{
    /** Marcas de configurable en las celdas. */
    public const CONFIGURABLE = 'cfg';        // configurable, por defecto OFF
    public const CONFIGURABLE_ON = 'cfg_on';  // configurable, por defecto ON

    /** Niveles estructurales (otorgado y bloqueado). */
    public const STRUCTURAL_LEVELS = ['crud', 'editar', 'ver', 'reportar', 'aprobar', 'c', 'auto'];

    /**
     * Roles del tenant (clave interna => etiqueta por defecto).
     * ROL-01 (Superadministrador) es de plataforma, no es rol de tenant.
     * ROL-10 (Sistema) y ROL-11 (Admin Tecnico) no llevan columna (D-07).
     */
    public const ROLES = [
        'rector' => 'Rector / Administrador del Colegio',            // ROL-02
        'coord_academico' => 'Coordinador Academico',                // ROL-03
        'coord_convivencia' => 'Coordinador de Convivencia',         // ROL-04
        'coord_combinado' => 'Coordinador Academico y de Convivencia', // ROL-05
        'secretaria' => 'Secretaria Academica',                      // ROL-06
        'docente' => 'Docente',                                      // ROL-07
        'director_grupo' => 'Director de Grupo',                     // ROL-08 (complemento)
        'estudiante' => 'Estudiante / Acudiente',                    // ROL-09
        'personal_apoyo' => 'Personal de Apoyo',                     // ROL-12
    ];

    /**
     * Definicion de permisos. Cada permiso: key, module, action y `cells`
     * (rol => valor de celda). Un rol ausente de `cells` = denegado.
     *
     * @return list<array{key:string,module:string,action:string,cells:array<string,string>}>
     */
    public static function permissions(): array
    {
        return [
            // ---- Configuracion del Colegio (todo Rector, estructural) ----
            ['key' => 'config.identidad', 'module' => 'Configuracion del Colegio', 'action' => 'Configurar logo, NIT, MEN', 'cells' => ['rector' => 'editar']],
            ['key' => 'config.calendario', 'module' => 'Configuracion del Colegio', 'action' => 'Definir periodos lectivos', 'cells' => ['rector' => 'editar']],
            ['key' => 'config.jornadas', 'module' => 'Configuracion del Colegio', 'action' => 'Configurar jornadas y bloques', 'cells' => ['rector' => 'editar']],
            ['key' => 'config.modelo_pedagogico', 'module' => 'Configuracion del Colegio', 'action' => 'Configurar modelo pedagogico por nivel', 'cells' => ['rector' => 'editar']],
            ['key' => 'config.escala_valorativa', 'module' => 'Configuracion del Colegio', 'action' => 'Configurar escala valorativa', 'cells' => ['rector' => 'editar']],
            ['key' => 'config.metodo_aprobacion', 'module' => 'Configuracion del Colegio', 'action' => 'Configurar metodo de aprobacion y nota minima', 'cells' => ['rector' => 'editar']],

            // ---- Academico (Fase 1 Bloque A): años lectivos, periodos y configuracion ----
            ['key' => 'academico.anos.gestionar', 'module' => 'academico', 'action' => 'anos.gestionar', 'cells' => ['rector' => 'crud', 'coord_academico' => 'crud', 'coord_combinado' => 'crud']],
            ['key' => 'academico.configurar', 'module' => 'academico', 'action' => 'configurar', 'cells' => ['rector' => 'editar', 'coord_academico' => 'editar', 'coord_combinado' => 'editar']],

            // ---- Academico (Fase 1 Bloque B): jerarquía organizacional ----
            // Sede→Jornada→Nivel→Grado→Grupo + bloques horarios + espacios físicos.
            ['key' => 'academico.estructura.gestionar', 'module' => 'academico', 'action' => 'estructura.gestionar', 'cells' => ['rector' => 'crud', 'coord_academico' => 'crud', 'coord_combinado' => 'crud']],

            // ---- Usuarios y Roles ----
            ['key' => 'usuarios.gestionar', 'module' => 'Usuarios y Roles', 'action' => 'Crear / editar / desactivar usuarios', 'cells' => ['rector' => 'crud']],
            ['key' => 'usuarios.asignar_roles', 'module' => 'Usuarios y Roles', 'action' => 'Asignar roles', 'cells' => ['rector' => 'editar']],
            ['key' => 'usuarios.ajustar_permisos', 'module' => 'Usuarios y Roles', 'action' => 'Ajustar permisos configurables', 'cells' => ['rector' => 'editar']],

            // ---- Notas y Consolidados ----
            ['key' => 'notas.registrar_materia_asignada', 'module' => 'Notas y Consolidados', 'action' => 'Registrar/editar notas en materia asignada', 'cells' => ['rector' => 'editar', 'coord_academico' => self::CONFIGURABLE, 'coord_combinado' => self::CONFIGURABLE, 'docente' => 'editar', 'director_grupo' => 'editar']],
            ['key' => 'notas.editar_no_dicta', 'module' => 'Notas y Consolidados', 'action' => 'Editar notas de materias que no dicta', 'cells' => ['rector' => 'editar', 'coord_academico' => self::CONFIGURABLE, 'coord_combinado' => self::CONFIGURABLE, 'director_grupo' => self::CONFIGURABLE]],
            ['key' => 'notas.editar_despues_cierre', 'module' => 'Notas y Consolidados', 'action' => 'Editar notas despues del cierre', 'cells' => ['rector' => 'editar', 'coord_academico' => self::CONFIGURABLE, 'coord_combinado' => self::CONFIGURABLE, 'docente' => self::CONFIGURABLE, 'director_grupo' => self::CONFIGURABLE]],
            ['key' => 'notas.ver_consolidado_grupo', 'module' => 'Notas y Consolidados', 'action' => 'Ver consolidado del grupo dirigido', 'cells' => ['rector' => 'ver', 'coord_academico' => 'ver', 'coord_combinado' => 'ver', 'director_grupo' => 'ver']],
            ['key' => 'notas.ver_consolidado_todos', 'module' => 'Notas y Consolidados', 'action' => 'Ver consolidado de todos los grupos', 'cells' => ['rector' => 'ver', 'coord_academico' => 'ver', 'coord_combinado' => 'ver']],
            ['key' => 'notas.ver_propias', 'module' => 'Notas y Consolidados', 'action' => 'Ver notas propias / del estudiante asociado', 'cells' => ['rector' => 'ver', 'coord_academico' => 'ver', 'coord_combinado' => 'ver', 'secretaria' => self::CONFIGURABLE, 'estudiante' => 'ver']],
            ['key' => 'notas.coordinar_cierre_periodo', 'module' => 'Notas y Consolidados', 'action' => 'Coordinar cierre de periodo', 'cells' => ['rector' => 'editar', 'coord_academico' => 'editar', 'coord_combinado' => 'editar']],
            ['key' => 'notas.gestionar_nivelaciones', 'module' => 'Notas y Consolidados', 'action' => 'Gestionar nivelaciones / habilitaciones', 'cells' => ['rector' => 'editar', 'coord_academico' => 'editar', 'coord_combinado' => 'editar']],

            // ---- Asistencia ----
            ['key' => 'asistencia.registrar_clases', 'module' => 'Asistencia', 'action' => 'Registrar asistencia en sus clases', 'cells' => ['rector' => 'editar', 'docente' => 'editar', 'director_grupo' => 'editar']],
            ['key' => 'asistencia.consultar_grupo', 'module' => 'Asistencia', 'action' => 'Consultar asistencia del grupo dirigido', 'cells' => ['rector' => 'ver', 'coord_academico' => 'ver', 'coord_combinado' => 'ver', 'director_grupo' => 'ver']],
            ['key' => 'asistencia.consultar_propia', 'module' => 'Asistencia', 'action' => 'Consultar asistencia propia / del estudiante', 'cells' => ['rector' => 'ver', 'coord_academico' => 'ver', 'coord_convivencia' => 'ver', 'coord_combinado' => 'ver', 'secretaria' => 'ver', 'estudiante' => 'ver']],

            // ---- Convivencia y Observador ----
            ['key' => 'observador.registrar_academico', 'module' => 'Convivencia y Observador', 'action' => 'Registrar observacion academica en su materia', 'cells' => ['rector' => 'editar', 'docente' => 'editar', 'director_grupo' => 'editar']],
            ['key' => 'observador.anotacion_grupo', 'module' => 'Convivencia y Observador', 'action' => 'Anotacion disciplinaria en grupo dirigido', 'cells' => ['rector' => 'editar', 'coord_convivencia' => 'editar', 'coord_combinado' => 'editar', 'director_grupo' => 'editar']],
            ['key' => 'observador.anotacion_cualquiera', 'module' => 'Convivencia y Observador', 'action' => 'Anotacion en cualquier estudiante', 'cells' => ['rector' => 'editar', 'coord_convivencia' => 'editar', 'coord_combinado' => 'editar']],
            ['key' => 'convivencia.citar_acudientes', 'module' => 'Convivencia y Observador', 'action' => 'Citar formalmente a acudientes', 'cells' => ['rector' => 'editar', 'coord_academico' => self::CONFIGURABLE, 'coord_convivencia' => self::CONFIGURABLE, 'coord_combinado' => self::CONFIGURABLE, 'director_grupo' => self::CONFIGURABLE]],
            ['key' => 'convivencia.definir_sanciones', 'module' => 'Convivencia y Observador', 'action' => 'Definir sanciones formales', 'cells' => ['rector' => 'editar', 'coord_convivencia' => self::CONFIGURABLE, 'coord_combinado' => self::CONFIGURABLE]],
            ['key' => 'convivencia.reportes', 'module' => 'Convivencia y Observador', 'action' => 'Generar reportes de convivencia', 'cells' => ['rector' => 'ver', 'coord_convivencia' => 'reportar', 'coord_combinado' => 'reportar']],

            // ---- Comunicaciones y Portal ----
            ['key' => 'comunicados.enviar_colegio', 'module' => 'Comunicaciones y Portal', 'action' => 'Enviar comunicado al colegio entero', 'cells' => ['rector' => 'editar', 'coord_academico' => self::CONFIGURABLE, 'coord_convivencia' => self::CONFIGURABLE, 'coord_combinado' => self::CONFIGURABLE, 'secretaria' => self::CONFIGURABLE]],
            ['key' => 'mensajes.acudientes', 'module' => 'Comunicaciones y Portal', 'action' => 'Comunicarse con acudientes via portal', 'cells' => ['rector' => 'editar', 'coord_academico' => self::CONFIGURABLE, 'coord_convivencia' => self::CONFIGURABLE, 'coord_combinado' => self::CONFIGURABLE, 'secretaria' => self::CONFIGURABLE, 'docente' => self::CONFIGURABLE, 'director_grupo' => self::CONFIGURABLE, 'personal_apoyo' => self::CONFIGURABLE]],
            ['key' => 'portal.acceder', 'module' => 'Comunicaciones y Portal', 'action' => 'Acceder al portal', 'cells' => ['rector' => 'ver', 'coord_academico' => 'ver', 'coord_convivencia' => 'ver', 'coord_combinado' => 'ver', 'secretaria' => 'ver', 'docente' => 'ver', 'director_grupo' => 'ver', 'estudiante' => self::CONFIGURABLE, 'personal_apoyo' => 'ver']],

            // ---- Auditoria ----
            ['key' => 'auditoria.logs_tenant', 'module' => 'Auditoria', 'action' => 'Acceder a logs del tenant', 'cells' => ['rector' => 'ver']],
            ['key' => 'auditoria.historial_registro', 'module' => 'Auditoria', 'action' => 'Ver historial de cambios de un registro', 'cells' => ['rector' => 'ver', 'coord_academico' => self::CONFIGURABLE, 'coord_convivencia' => self::CONFIGURABLE, 'coord_combinado' => self::CONFIGURABLE, 'secretaria' => self::CONFIGURABLE]],
        ];
    }

    /** Todas las claves de permiso (para crear los permisos en spatie). */
    public static function permissionKeys(): array
    {
        return array_map(static fn (array $p): string => $p['key'], self::permissions());
    }

    /** Claves de rol del tenant. */
    public static function roleKeys(): array
    {
        return array_keys(self::ROLES);
    }

    /**
     * Clasifica una celda (rol x permiso).
     *
     * @return array{type:'structural'|'configurable'|'denied', level:?string, default:bool}
     */
    public static function classifyCell(?string $cell): array
    {
        if ($cell === null) {
            return ['type' => 'denied', 'level' => null, 'default' => false];
        }
        if ($cell === self::CONFIGURABLE || $cell === self::CONFIGURABLE_ON) {
            return ['type' => 'configurable', 'level' => null, 'default' => $cell === self::CONFIGURABLE_ON];
        }

        return ['type' => 'structural', 'level' => $cell, 'default' => true];
    }

    /**
     * Permisos que se OTORGAN por defecto a un rol al sembrar: todos los
     * estructurales + los configurables con default ON.
     *
     * @return list<string>
     */
    public static function defaultGrantsFor(string $roleKey): array
    {
        $grants = [];
        foreach (self::permissions() as $permission) {
            $info = self::classifyCell($permission['cells'][$roleKey] ?? null);
            if ($info['type'] === 'structural' || ($info['type'] === 'configurable' && $info['default'])) {
                $grants[] = $permission['key'];
            }
        }

        return $grants;
    }

    /** Devuelve la clasificacion de una celda concreta por claves. */
    public static function cellFor(string $roleKey, string $permKey): array
    {
        foreach (self::permissions() as $permission) {
            if ($permission['key'] === $permKey) {
                return self::classifyCell($permission['cells'][$roleKey] ?? null);
            }
        }

        return ['type' => 'denied', 'level' => null, 'default' => false];
    }

    /** ¿La celda (rol x permiso) es configurable por el colegio? */
    public static function isConfigurable(string $roleKey, string $permKey): bool
    {
        return self::cellFor($roleKey, $permKey)['type'] === 'configurable';
    }
}
