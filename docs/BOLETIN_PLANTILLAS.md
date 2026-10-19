# Plantillas de boletín: editor y vista previa viva

**Corte local: 14 de octubre de 2026.** Este incremento prepara el diseño de los boletines; **no genera, aprueba ni publica un PDF académico**. La vista previa usa exclusivamente datos ficticios en memoria, identificados como muestra. No crea estudiantes, matrículas ni calificaciones.

## Alcance

- `GET /api/boletines/plantillas` devuelve las bases de período y final, el nombre del colegio y hasta 20 diseños guardados por página. Un colegio con acceso al módulo puede consultar el diseño base aun si no tiene la capacidad de personalización.
- `POST /api/boletines/plantillas` crea un borrador. `PUT /api/boletines/plantillas/{token}` guarda cambios con versión optimista. `POST /api/boletines/plantillas/{token}/publicar` conserva una revisión publicada separada e inmutable en la aplicación; editar después el borrador no altera la revisión previa.
- Solo quien tenga `boletines.plantillas.ver` o `boletines.plantillas.gestionar` puede consultar. Crear, editar y publicar exigen **ambos**: permiso `boletines.plantillas.gestionar` y capacidad efectiva `boletines_personalizables` (Estándar/Premium por defecto o plan personalizado que la incluya). La matriz inicial concede la gestión únicamente al Rector. El backend revalida; ocultar un botón no concede acceso.
- Las rutas usan un token opaco de plantilla. No se aceptan IDs de base de datos desde el navegador. Cada escritura se audita y un `version` desactualizado responde 409.
- El JSON de diseño permite acento hexadecimal, tipografía entre opciones, densidad, título, subtítulo y siete bloques ordenables. Datos del estudiante y asignaturas permanecen visibles. No se admite HTML, activos remotos ni expresiones ejecutables. El esquema se valida de nuevo en servidor.
- El editor ofrece paleta, selección de fuente/densidad, orden/visibilidad de bloques y vista previa simultánea para boletín de período y final. Muestra el nombre y logo institucional configurados cuando están disponibles; los datos académicos de la muestra son ficticios. La vista indica expresamente que no es un boletín oficial.

## Persistencia

- Central: `2026_10_14_000003_register_bulletin_template_permissions.php` registra permisos y regla de plan.
- Tenant: `2026_10_14_000009_create_bulletin_templates.php` crea borradores y revisiones; `2026_10_14_000010_grant_bulletin_template_permissions.php` sincroniza permisos iniciales.
- Las revisiones publicadas no tienen API de actualización o eliminación. Su número es único por plantilla. El diseño que se use en una emisión futura deberá guardar la revisión exacta en el expediente emitido.

Las tres migraciones se aplicaron a la base central y a los cinco tenants **locales** existentes tras respaldar las seis bases y ensayar tanto un PostgreSQL nuevo como una restauración de Inmaculada. Los respaldos verificados están en `C:\Users\Pedro\AppData\Local\Temp\colegio-eventos-20261014-201933-df20caee820d`; la carpeta temporal no equivale a retención segura. No se migró producción ni se crearon diseños en colegios existentes.

## Verificación de este corte

`php artisan test --compact`: **385 pruebas, 3688 aserciones** aprobadas, incluidas lectura del diseño base en plan básico, denegación al docente, validación de bloques/HTML, revisiones publicadas, conflicto 409 y bloqueo tras bajar de plan. PostgreSQL: ensayo desde cero y restaurado aprobados; después de aplicar, las tres migraciones figuran ejecutadas y los cinco tenants no tienen pendientes en esas rutas. Frontend: **53 pruebas**, compilación TypeScript/Vite y presupuesto de carga, lint focalizado y auditoría de **1716 claves** ES/EN sin faltantes. Falta validación visual de campo con usuarios reales y la emisión oficial.

## Pendiente antes de emisión oficial

La emisión final debe incorporar logo y datos institucionales reales al documento persistido, enlazar resultados/indicadores/asistencia y observación aprobada con una revisión publicada, controlar preparación y aprobación individual por estudiante, generar y custodiar PDF/huella, y conservar las sustituciones históricas. No se debe usar la vista previa ni la impresión del navegador como emisión oficial.
