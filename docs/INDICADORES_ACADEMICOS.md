# Indicadores académicos descriptivos

Los indicadores describen el aprendizaje esperado en una asignatura, grado y período. No son una calificación individual ni tienen escala, estado de logro o porcentaje propios. La valoración del estudiante proviene de las notas y reglas SIEE; el docente puede complementar el boletín con una observación individual.

## Flujo

- En **Académico → Indicadores**, quien tenga `indicadores.base.gestionar` crea un texto institucional y lo vincula a un año, grado y asignatura. Puede aplicarlo a todos los períodos o a períodos concretos. Cada revisión conserva la redacción anterior en los vínculos existentes hasta que se adopte expresamente la nueva revisión.
- El docente asignado consulta los indicadores de su materia y grupo, incluso si no tiene permiso de edición. Solo puede redactar una variante visible en **su grupo** si el Rector habilita `docente_edita_indicadores_grupo` y su rol posee `indicadores.grupo.editar`. Las variantes no cambian el texto institucional ni los demás grupos. No se editan períodos cerrados.
- En la planilla y el boletín se presenta el texto correspondiente al período y grupo. El boletín usa las calificaciones SIEE y la observación aprobada para reflejar el desempeño; la emisión individual conserva un snapshot del texto descriptivo visible. Ninguna valoración de indicador condiciona la emisión.
- Las rutas de consulta y escritura usan tokens opacos dentro del tenant; la edición versionada detecta cambios concurrentes.

## Datos y migración

Se conservan `indicadores_base`, `indicadores_revisiones`, `indicadores_vinculos` e `indicadores_variantes`. Las antiguas tablas `indicadores_configuracion` y `indicadores_valoraciones`, el permiso `indicadores.valoraciones.registrar` y las rutas `/indicadores/politica/{ano}` y `/indicadores/vinculos/{vinculo}/valoraciones/{matricula}` se retiran mediante las migraciones del 19 de octubre de 2026. La migración tenant **se detiene si encuentra datos** en alguna tabla antigua: deben respaldarse y decidir su tratamiento antes de retirarla en un entorno que sí haya usado esa funcionalidad. No se borran los indicadores descriptivos ni las observaciones.

La escala valorativa del SIEE, las notas por asignatura y las observaciones del boletín son módulos independientes y permanecen vigentes. Las migraciones y pruebas locales no constituyen una autorización de despliegue a producción.

Aplicación local del 19 de octubre de 2026: respaldo verificado con `pg_restore --list` de la base central y cinco tenants en `storage/app/local-sample-backups/indicator-retirement-20261019-214443/`. Antes de migrar, las dos tablas retiradas tenían cero registros en cada tenant. Después se verificó que el permiso antiguo no existe en central ni tenants, que Inmaculada conserva 324 vínculos de indicadores y 196 observaciones, y que las tablas antiguas no existen. El respaldo local no sustituye una copia externa de producción.
