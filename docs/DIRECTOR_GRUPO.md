# Dirección de grupo — incremento local del 15 de octubre de 2026

Cada grupo de un año lectivo puede tener un docente director activo. Esta relación es distinta de la asignación a una materia o Aula y no participa en el cálculo de promoción. No se asignó automáticamente ningún director a los colegios existentes.

## Contrato y autorización

- `GET /api/estructura/grupos/{grupo}/director?opaque=1`: consulta director vigente e historial reciente. Exige `academico.estructura.gestionar` y selector opaco de grupo.
- `PUT /api/estructura/grupos/{grupo}/director?opaque=1`: asigna, cambia o retira director. Recibe `docente_token` (nulo para retirar), `version` y `motivo` al cambiar una asignación existente. Rechaza docentes inactivos o sin rol docente, grupos inactivos, años no editables y conflictos de versión. Exige el mismo permiso de estructura; registra actor e historial.
- `GET /api/director-grupo/mis-grupos?opaque=1`: devuelve solo los grupos activos dirigidos por el usuario autenticado. No concede acceso a datos de otros grupos.

La relación no concede por sí misma permisos para editar notas, materias, indicadores, boletines o estudiantes. Esos accesos deberán implementarse y comprobarse explícitamente por grupo cuando el flujo lo requiera.

## Persistencia y verificación

La migración tenant `2026_10_15_000001_create_group_directors.php` crea `directores_grupo` y `directores_grupo_historial`. Se respaldaron las cinco bases tenant locales en `C:\Users\Pedro\AppData\Local\Temp\colegio-directores-20261015-145537` con `pg_dump` formato custom y se verificó cada respaldo con `pg_restore --list`. Luego se aplicó la migración a esos cinco tenants; un segundo `tenants:migrate --pretend` reportó «Nothing to migrate» en los cinco. La carpeta de Temp no constituye retención permanente ni se migró producción.

La prueba `test_group_director_is_scoped_versioned_and_does_not_create_subject_assignment` pasó: cubre asignación, historial, versión, motivo, permiso y ausencia de asignación de materia. El build TypeScript/Vite y la auditoría ES/EN también pasaron. Pendiente: recorrido visual y de campo con el Rector, y verificación integral de los demás módulos del ciclo.
