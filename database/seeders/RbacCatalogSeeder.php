<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Rbac\RbacMatrixCell;
use App\Models\Rbac\RbacPermission;
use App\Models\Rbac\RbacRole;
use App\Rbac\PermissionMatrix;
use Illuminate\Database\Seeder;

/**
 * Siembra el catalogo CENTRAL de RBAC (rbac_permissions / rbac_roles /
 * rbac_matrix) a partir del catalogo base en codigo (App\Rbac\PermissionMatrix)
 * mas unos permisos "gated" de ejemplo ligados a features de plan.
 *
 * A partir de aqui la fuente de verdad son estas tablas (editables por el
 * superadmin); PermissionMatrix queda solo como semilla inicial.
 *
 * Idempotente (updateOrCreate por key). Corre en la BD CENTRAL.
 */
class RbacCatalogSeeder extends Seeder
{
    /** Mapeo modulo base -> feature de plan (null = nucleo, en todos los planes). */
    private const MODULE_FEATURE = [
        'Asistencia' => 'asistencia',
        'Comunicaciones y Portal' => 'comunicacion_basica',
    ];

    public function run(): void
    {
        // 1. Roles (del catalogo base).
        $order = 0;
        foreach (PermissionMatrix::ROLES as $key => $label) {
            RbacRole::updateOrCreate(
                ['key' => $key],
                ['label' => $label, 'is_system' => true, 'sort_order' => $order++],
            );
        }

        // 2. Permisos base (PermissionMatrix) + permisos gated de ejemplo.
        $catalog = [];
        foreach (PermissionMatrix::permissions() as $perm) {
            $catalog[] = [
                'key' => $perm['key'],
                'module' => $perm['module'],
                'action' => $perm['action'],
                'feature' => self::MODULE_FEATURE[$perm['module']] ?? null,
                'cells' => $perm['cells'],
            ];
        }
        foreach ($this->gatedExtras() as $extra) {
            $catalog[] = $extra;
        }

        // 3. Permisos + celdas de la matriz.
        $order = 0;
        foreach ($catalog as $entry) {
            RbacPermission::updateOrCreate(
                ['key' => $entry['key']],
                [
                    'module' => $entry['module'],
                    'action' => $entry['action'],
                    'feature_key' => $entry['feature'],
                    'is_system' => true,
                    'sort_order' => $order++,
                ],
            );

            foreach ($entry['cells'] as $roleKey => $cellValue) {
                $info = PermissionMatrix::classifyCell($cellValue);
                if ($info['type'] === 'denied') {
                    continue; // ausencia = denegado
                }

                RbacMatrixCell::updateOrCreate(
                    ['role_key' => $roleKey, 'permission_key' => $entry['key']],
                    [
                        'type' => $info['type'],
                        'level' => $info['level'],
                        'default_granted' => $info['default'],
                    ],
                );
            }
        }
    }

    /**
     * Permisos de ejemplo ligados a features de plan (para que el gating sea
     * observable end-to-end). Son declarativos hasta que exista codigo que los
     * aplique, pero representan capacidades reales por plan.
     *
     * @return list<array{key:string,module:string,action:string,feature:string,cells:array<string,string>}>
     */
    private function gatedExtras(): array
    {
        return [
            [
                'key' => 'comunicados.whatsapp',
                'module' => 'Comunicaciones y Portal',
                'action' => 'Enviar comunicados por WhatsApp',
                'feature' => 'whatsapp', // solo Premium
                'cells' => [
                    'rector' => PermissionMatrix::CONFIGURABLE_ON,
                    'coord_academico' => PermissionMatrix::CONFIGURABLE,
                    'coord_convivencia' => PermissionMatrix::CONFIGURABLE,
                    'coord_combinado' => PermissionMatrix::CONFIGURABLE,
                    'secretaria' => PermissionMatrix::CONFIGURABLE,
                ],
            ],
            [
                'key' => 'reportes.financieros',
                'module' => 'Reportes',
                'action' => 'Ver reportes financieros',
                'feature' => 'reportes_financieros', // Estandar+
                'cells' => [
                    'rector' => 'ver',
                    'secretaria' => PermissionMatrix::CONFIGURABLE,
                ],
            ],
            [
                'key' => 'boletines.personalizar',
                'module' => 'Boletines',
                'action' => 'Personalizar plantillas de boletin',
                'feature' => 'boletines_personalizables', // Estandar+
                'cells' => [
                    'rector' => 'editar',
                    'coord_academico' => PermissionMatrix::CONFIGURABLE,
                    'coord_combinado' => PermissionMatrix::CONFIGURABLE,
                ],
            ],
        ];
    }
}
