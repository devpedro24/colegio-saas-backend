<?php

declare(strict_types=1);

namespace App\Rbac;

/** Capacidad comercial que habilita cada permiso del catálogo inicial. */
final class PermissionPlanFeature
{
    public const RETIRED_GUARDIAN_PERMISSIONS = [
        'convivencia.citar_acudientes',
        'mensajes.acudientes',
    ];

    public static function forKey(string $key): string
    {
        if ($key === 'academico.preinformes.ver' || $key === 'academico.preinformes.gestionar') {
            return 'preinformes';
        }
        if ($key === 'boletines.plantillas.gestionar' || $key === 'boletines.personalizar') {
            return 'boletines_personalizables';
        }
        if ($key === 'aula.apariencia.configurar') {
            return 'aula_colores';
        }
        if ($key === 'comunicados.whatsapp') {
            return 'whatsapp';
        }
        if ($key === 'reportes.financieros') {
            return 'reportes_financieros';
        }
        if (str_starts_with($key, 'aula.')) {
            return 'aula';
        }
        if (str_starts_with($key, 'asistencia.')) {
            return 'asistencia';
        }
        if (str_starts_with($key, 'pagos.')) {
            return 'pagos_pension';
        }
        if (str_starts_with($key, 'eventos.') || str_starts_with($key, 'comunicados.')
            || str_starts_with($key, 'mensajes.')) {
            return 'comunicacion_basica';
        }
        if (in_array($key, [
            'config.calendario', 'config.jornadas', 'config.modelo_pedagogico',
            'config.escala_valorativa', 'config.metodo_aprobacion',
        ], true) || str_starts_with($key, 'academico.') || str_starts_with($key, 'ingreso.')
            || str_starts_with($key, 'notas.') || str_starts_with($key, 'boletines.')
            || str_starts_with($key, 'indicadores.') || str_starts_with($key, 'observador.')
            || str_starts_with($key, 'convivencia.') || str_starts_with($key, 'estudiantes.egreso.')) {
            return 'academico';
        }

        // Identidad, acceso al portal, gestión de usuarios, correo institucional,
        // archivos y auditoría deben seguir operativos en cualquier plan.
        return 'nucleo';
    }
}
