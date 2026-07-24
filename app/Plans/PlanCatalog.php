<?php

declare(strict_types=1);

namespace App\Plans;

/**
 * Catalogo CERRADO de features (configurables) y limites de los planes SaaS,
 * fiel a la documentacion de negocio:
 *   - Logica del negocio/06-monetizacion-y-pagos/planes-y-suscripciones.md
 *   - Logica del negocio/15-valor-agregado/catalogo-de-diferenciadores.md (RN-VA-001..008)
 *   - Logica del negocio/11-plataforma-y-operacion/almacenamiento-y-cuotas.md
 *
 * El superadministrador arma cada plan activando/desactivando estas features y
 * fijando los limites cuantitativos. Igual que la matriz de permisos (RBAC), el
 * catalogo es cerrado: el frontend NO inventa features, solo togglea las de aqui.
 *
 * NOTA: separar del RBAC. Esto define QUE trae el plan del colegio (nivel SaaS);
 * la matriz de permisos (App\Rbac\PermissionMatrix) define quien puede que DENTRO
 * del colegio.
 */
class PlanCatalog
{
    /** Categorias para agrupar las features en la UI (clave => etiqueta i18n). */
    public const CATEGORIES = [
        'academico' => 'plan.cat.academico',
        'pagos' => 'plan.cat.pagos',
        'comunicacion' => 'plan.cat.comunicacion',
        'academico_avanzado' => 'plan.cat.academicoAvanzado',
        'reportes' => 'plan.cat.reportes',
        'soporte' => 'plan.cat.soporte',
    ];

    /**
     * Features vendibles / opt-in por plan. Cada una:
     *   key, category, label (clave i18n), description (clave i18n).
     *
     * @return list<array{key:string,category:string,label:string,description:string}>
     */
    public static function features(): array
    {
        return [
            // ---- Academico y operacion (nucleo) ----
            ['key' => 'academico', 'category' => 'academico', 'label' => 'plan.f.academico', 'description' => 'plan.f.academico.desc'],
            ['key' => 'asistencia', 'category' => 'academico', 'label' => 'plan.f.asistencia', 'description' => 'plan.f.asistencia.desc'],

            // ---- Pagos y facturacion ----
            ['key' => 'pagos_pension', 'category' => 'pagos', 'label' => 'plan.f.pagosPension', 'description' => 'plan.f.pagosPension.desc'],
            ['key' => 'multi_pasarela', 'category' => 'pagos', 'label' => 'plan.f.multiPasarela', 'description' => 'plan.f.multiPasarela.desc'],
            ['key' => 'pagos_sin_friccion', 'category' => 'pagos', 'label' => 'plan.f.pagosSinFriccion', 'description' => 'plan.f.pagosSinFriccion.desc'],
            ['key' => 'dian', 'category' => 'pagos', 'label' => 'plan.f.dian', 'description' => 'plan.f.dian.desc'],

            // ---- Comunicacion ----
            ['key' => 'comunicacion_basica', 'category' => 'comunicacion', 'label' => 'plan.f.comunicacionBasica', 'description' => 'plan.f.comunicacionBasica.desc'],
            ['key' => 'app_acudientes', 'category' => 'comunicacion', 'label' => 'plan.f.appAcudientes', 'description' => 'plan.f.appAcudientes.desc'],
            ['key' => 'whatsapp', 'category' => 'comunicacion', 'label' => 'plan.f.whatsapp', 'description' => 'plan.f.whatsapp.desc'],

            // ---- Academico avanzado ----
            ['key' => 'boletines_personalizables', 'category' => 'academico_avanzado', 'label' => 'plan.f.boletines', 'description' => 'plan.f.boletines.desc'],
            ['key' => 'multi_sede', 'category' => 'academico_avanzado', 'label' => 'plan.f.multiSede', 'description' => 'plan.f.multiSede.desc'],
            ['key' => 'firma_electronica', 'category' => 'academico_avanzado', 'label' => 'plan.f.firmaElectronica', 'description' => 'plan.f.firmaElectronica.desc'],
            ['key' => 'carne_qr', 'category' => 'academico_avanzado', 'label' => 'plan.f.carneQr', 'description' => 'plan.f.carneQr.desc'],
            ['key' => 'generador_horarios', 'category' => 'academico_avanzado', 'label' => 'plan.f.generadorHorarios', 'description' => 'plan.f.generadorHorarios.desc'],

            // ---- Reportes y analitica ----
            ['key' => 'reportes_financieros', 'category' => 'reportes', 'label' => 'plan.f.reportesFinancieros', 'description' => 'plan.f.reportesFinancieros.desc'],
            ['key' => 'bi_avanzado', 'category' => 'reportes', 'label' => 'plan.f.biAvanzado', 'description' => 'plan.f.biAvanzado.desc'],

            // ---- Soporte e integraciones ----
            ['key' => 'soporte_prioritario', 'category' => 'soporte', 'label' => 'plan.f.soportePrioritario', 'description' => 'plan.f.soportePrioritario.desc'],
            ['key' => 'sso', 'category' => 'soporte', 'label' => 'plan.f.sso', 'description' => 'plan.f.sso.desc'],
        ];
    }

    /**
     * Limites cuantitativos de un plan. `null` en el valor del plan = ilimitado.
     * `unit` es una etiqueta corta (clave i18n) opcional.
     *
     * @return list<array{key:string,label:string,unit:?string}>
     */
    public static function limits(): array
    {
        return [
            ['key' => 'max_estudiantes', 'label' => 'plan.limit.maxEstudiantes', 'unit' => null],
            ['key' => 'storage_gb', 'label' => 'plan.limit.storageGb', 'unit' => 'plan.unit.gb'],
            ['key' => 'max_sedes', 'label' => 'plan.limit.maxSedes', 'unit' => null],
            ['key' => 'max_pasarelas', 'label' => 'plan.limit.maxPasarelas', 'unit' => null],
        ];
    }

    /** Todas las claves de feature validas (para validar el request). */
    public static function featureKeys(): array
    {
        return array_map(static fn (array $f): string => $f['key'], self::features());
    }

    /** Claves de limite validas. */
    public static function limitKeys(): array
    {
        return array_map(static fn (array $l): string => $l['key'], self::limits());
    }

    /**
     * Definicion por defecto de los 3 planes comerciales (para el seed).
     * `features` = subconjunto de featureKeys(); limites null = ilimitado.
     *
     * @return list<array<string,mixed>>
     */
    public static function defaultPlans(): array
    {
        $esencial = ['academico', 'asistencia', 'comunicacion_basica', 'pagos_pension'];

        $estandar = array_merge($esencial, [
            'multi_pasarela', 'pagos_sin_friccion', 'app_acudientes',
            'boletines_personalizables', 'multi_sede', 'firma_electronica',
            'carne_qr', 'reportes_financieros',
        ]);

        $premium = array_merge($estandar, [
            'dian', 'whatsapp', 'generador_horarios', 'bi_avanzado',
            'soporte_prioritario', 'sso',
        ]);

        return [
            [
                'key' => 'esencial',
                'name' => 'Esencial',
                'description' => 'Colegios pequenos (menos de 300 estudiantes).',
                'max_estudiantes' => 300,
                'storage_gb' => 50,
                'max_sedes' => 1,
                'max_pasarelas' => 1,
                'features' => $esencial,
                'sort_order' => 1,
            ],
            [
                'key' => 'estandar',
                'name' => 'Estandar',
                'description' => 'Colegios medianos (300 a 800 estudiantes).',
                'max_estudiantes' => 800,
                'storage_gb' => 200,
                'max_sedes' => 5,
                'max_pasarelas' => 3,
                'features' => $estandar,
                'sort_order' => 2,
            ],
            [
                'key' => 'premium',
                'name' => 'Premium',
                'description' => 'Colegios grandes (800+ estudiantes) o con necesidades avanzadas.',
                'max_estudiantes' => null,
                'storage_gb' => 1024,
                'max_sedes' => null,
                'max_pasarelas' => null,
                'features' => $premium,
                'sort_order' => 3,
            ],
        ];
    }
}
