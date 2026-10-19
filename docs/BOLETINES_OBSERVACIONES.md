# Primer incremento del cierre: reglas y observaciones de boletín

La ruta `GET /api/evaluacion/boletines/{matricula}` es una vista preliminar; la emisión oficial individual, el PDF privado y la publicación siguen el flujo separado descrito en `BOLETIN_EMISION.md`.

## Regla institucional

`GET/PUT /api/boletines/politica` consulta y configura dos reglas permanentes del colegio:

- `requiere_observacion_final`: por defecto `false`. Una observación final anual adicional será exigible al emitir solo cuando el colegio la active. No sustituye la observación de cada período.
- `docente_edita_indicadores_grupo`: por defecto `false`. Habilita las variantes de indicadores **solo** para la materia y grupo asignados al docente que además tenga `indicadores.grupo.editar`. No permite alterar la base institucional ni otros grupos.

La interfaz permite configurar esta regla. Los indicadores son textos descriptivos, sin escala propia ni valoración individual; ver `INDICADORES_ACADEMICOS.md`.

El `PUT` requiere `boletines.politica.gestionar`, ambas opciones booleanas y la `version` actual. Una versión vieja produce 409. No se copia la regla a cada año.
Quien tiene `indicadores.grupo.editar` puede consultar la regla para saber si su editor está habilitado, pero no modificarla ni acceder por ello a observaciones.

## Observaciones individuales

`GET/PUT /api/boletines/observaciones/{matriculaToken}` devuelve/guarda una observación por período real o para el final anual. `POST .../aprobar` requiere permiso separado. Las escrituras llevan `contexto=periodo` con `periodo_token` del mismo año, o `contexto=final` sin período; también `version` optimista. El primer borrador usa versión 0. Editar una aprobada la devuelve a borrador y obliga a revisar otra vez. El historial guarda cada revisión con texto, estado, autor y fecha; la auditoría general registra la acción sin duplicar contenido personal.

Los selectores de matrícula/período son opacos y se resuelven dentro del tenant. No se devuelve ID interno. El permiso de lectura/edición/aprobación es distinto; ni un docente solo por ser docente ni un estudiante puede abrir este formulario administrativo. La asignación de director de grupo se documenta en `DIRECTOR_GRUPO.md`.
Un período cerrado puede recibir la observación de cierre, pero un año ya cerrado bloquea nuevas escrituras ordinarias; su eventual corrección histórica requiere un flujo autorizado aún pendiente.

Una observación aprobada puede incluirse en el snapshot de una emisión individual. Su estado `aprobada` se refiere al texto de la observación, **no** a la aprobación o publicación del boletín.

Migraciones: central `2026_10_14_000001_register_bulletin_permissions.php`; tenant `2026_10_14_000005_create_bulletin_observations.php` y `000006_grant_bulletin_permissions.php`. Se aplicaron el 14 de octubre de 2026 **solo a PostgreSQL local** (central y cinco colegios) tras respaldar cada base, verificar su catálogo y ensayar esquema nuevo y actualización de una restauración aislada. Respaldos: `C:\Users\Pedro\AppData\Local\Temp\colegio-eventos-20261014-193558-12855b480d38`; al estar en Temp no constituyen retención externa. La simulación `--pretend` de la migración de permisos no funciona por el ID devuelto por Spatie en una inserción simulada; el ensayo de migración real sobre la restauración sí pasó. No se ejecutaron las migraciones pendientes de admisiones ni se modificaron datos académicos existentes.

Prueba aislada: `php artisan test --filter='test_bulletin_(policy|observations)' tests/Feature/AcademicModulesTest.php`.
