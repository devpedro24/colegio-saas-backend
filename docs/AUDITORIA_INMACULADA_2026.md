# Auditoría académica local de Inmaculada — 2026

## Alcance y naturaleza de los datos

Esta carga se hizo **solo en el tenant local `inmaculada`**, para recorrer el ciclo académico con información verosímil pero **sintética**. No acredita alumnos, calificaciones ni actuaciones históricas reales del colegio. Las fechas pedagógicas de las actividades corresponden a cada preinforme; los timestamps de inserción y las entradas de auditoría conservan la fecha real de la carga. Hay actividades del cuarto período con fechas posteriores al 3 de octubre de 2026 porque el objetivo era revisar el año completo; no deben publicarse como registros oficiales.

El primer grupo de cada grado Prejardín, Jardín, Transición, Primero, Segundo, Tercero y Cuarto quedó lleno hasta su cupo: 25 estudiantes por grupo de preescolar y 30 por grupo de primaria, **195 matrículas activas** en total. Se preservaron las dos matrículas iniciales; se añadieron 193 cuentas de estudiante con contraseñas aleatorias no publicadas y correos `.example.invalid`. Los usuarios de prueba deben cambiar o restablecer la contraseña por el flujo administrativo antes de iniciar sesión.

Los siete grados tenían 11 materias en su currículo. Se completaron las 15 asignaciones que faltaban en los tres grupos de preescolar, se conservaron los docentes ya asignados y se crearon 11 cuentas docentes sintéticas para cubrir las materias restantes. Se sincronizaron 200 sesiones del horario que aún tenían docente vacío, sin detectar cruces entre clases. No se alteró la estructura escolar ni los horarios de los otros grupos.

Los horarios de los tres grupos de preescolar siguen distribuyendo sus 30 sesiones semanales entre las seis dimensiones originales; las cinco materias generales añadidas al currículo **no tienen una franja propia**. Añadirlas sin criterio excedería la intensidad/tiempo de la jornada o desplazaría clases existentes. Están disponibles para asignación, evaluación y boletín, pero la distribución de horario debe ser una decisión pedagógica posterior del colegio.

## Evaluación

- Los cuatro períodos usan cuatro preinformes de pesos 20 %, 25 %, 25 % y 30 %. Los períodos 1–3 se reabrieron de forma excepcional para cargar datos y **volvieron a quedar cerrados**. El cuarto permanece abierto.
- Las siete actividades anteriores del cuarto período pasaron al primer preinforme conservando sus identificadores y **14 calificaciones originales**. Las definitivas pudieron variar al completar los otros preinformes; no se cambió el valor de esas 14 notas.
- Se añadieron **68.656 calificaciones**, para un total de **68.670** en el tenant. Las planillas de las 11 materias de cada grupo tienen actividades y valoraciones en los cuatro períodos. La comprobación SQL de cobertura no encontró celdas sin valoración en los siete grupos.
- Entre las notas sintéticas, se ajustaron con auditoría **123 valores** de Matemáticas para obtener un caso de bajo desempeño anual en cada grupo de primaria. Así también hay escenarios útiles para revisar recuperación y promoción, sin cambiar las 14 calificaciones preexistentes.
- Los boletines **preliminares** de un estudiante de cada grupo se calcularon sin asignaturas pendientes. La aplicación todavía no implementa la aprobación, firma o emisión de un boletín oficial ni la promoción automática de estos estudiantes.

## Escala visual de preescolar

La migración tenant `2026_10_03_000001_create_escala_opciones.php` agrega categorías visuales por escala y la referencia opcional desde cada calificación. Se aplicó en los cinco tenants locales y también queda en la secuencia de migraciones de colegios futuros. No modifica las notas numéricas existentes.

La escala de preescolar 2026 de Inmaculada tiene inicialmente cuatro categorías configurables:

| Categoría | Valor interno | Aprueba |
| --- | ---: | --- |
| Excelente 😄 | 5.0 | Sí |
| Bien 🙂 | 4.0 | Sí |
| En proceso 😐 | 2.5 | No |
| Necesita apoyo 😟 | 1.5 | No |

Los valores internos se usan para promediar actividades, preinformes, períodos y áreas con el mismo motor decimal exacto del SIEE. La categoría final es la más cercana al resultado exacto; ante un empate se elige la de menor valor. La planilla y el boletín preliminar de preescolar presentan la categoría, no la nota numérica. Una escala nueva admite entre 2 y 8 categorías con nombre, emoji, equivalencia y condición de aprobación propios. El colegio puede subir una imagen PNG/JPEG propia para cada categoría desde la configuración; hasta entonces se muestra el emoji. La imagen se sirve de forma autenticada desde almacenamiento del tenant. Después de registrar valoraciones, cantidad, orden, equivalencias y condición de aprobación quedan protegidos para no reinterpretar notas anteriores; nombres e imágenes sí pueden modificarse. Una política futura de cambios retrospectivos requeriría versionar la escala o una conversión explícita y auditada.

## Reproducción y respaldo

Los scripts en `scripts/` son **específicos de Inmaculada 2026**, tienen vista previa por defecto y requieren `--apply` para escribir. No son seeders globales ni se ejecutan al aprovisionar colegios. Orden usado: `cargar_inmaculada_2026_auditoria_matriculas.php`, `configurar_preinformes_inmaculada_2026.php`, `convertir_cuarto_periodo_inmaculada_2026.php`, `configurar_caritas_inmaculada_2026.php`, `completar_auditoria_inmaculada_2026.php`, `sincronizar_horarios_inmaculada_2026.php` y `variar_resultados_inmaculada_2026.php`. El verificador de solo lectura es `verificar_auditoria_inmaculada_2026.php`.

Respaldos `pg_dump` en formato personalizado, verificados con `pg_restore --list`, fuera del repositorio:

- `C:\Users\Pedro\Documents\Respaldos Colegio SaaS\inmaculada-2026-previo-auditoria-20261003.dump`
- `C:\Users\Pedro\Documents\Respaldos Colegio SaaS\inmaculada-2026-antes-de-notas-20261003.dump`
- `C:\Users\Pedro\Documents\Respaldos Colegio SaaS\pre-escala-20261003\` (cinco tenants, antes de la migración visual)
- `C:\Users\Pedro\Documents\Respaldos Colegio SaaS\inmaculada-2026-auditoria-completa-20261003.dump` (estado posterior; 800.393 bytes)
- `C:\Users\Pedro\Documents\Respaldos Colegio SaaS\inmaculada-2026-auditoria-final-20261003.dump` (estado final, con casos de promoción; 806.378 bytes)

Estos respaldos no sustituyen una prueba de restauración ni una política de custodia externa. No ejecutar los scripts de carga en producción ni en otro tenant. El verificador comprueba que los cuatro períodos conserven sus estados esperados y que la vista pública de preescolar no devuelva resultados numéricos.

Verificación de código: suite completa del backend, **248 pruebas/1.833 aserciones**, y dos pruebas específicas adicionales de configuración visual, carga de imagen y captura de caritas; frontend, **32 pruebas**, compilación y auditoría de **1.117 claves de traducción** sin faltantes. `tenants:migrate --pretend` no encontró migraciones pendientes en los cinco tenants. La comprobación de boletines y cobertura se hizo contra la base PostgreSQL local de Inmaculada; no sustituye una prueba visual de navegador ni una restauración del respaldo en un entorno separado.
