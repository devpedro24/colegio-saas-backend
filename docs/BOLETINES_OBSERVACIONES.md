# Primer incremento del cierre: reglas y observaciones de boletín

## Actualización del 23 de octubre de 2026

Guardar una observación la deja disponible para generar el boletín: **ya no requiere aprobación**. Se mantienen la versión optimista, el autor y el historial. La observación de período solo se modifica con el período abierto y el año en curso; la observación final, con el año en curso. El catálogo informa `puede_editar` por período y `motivo_bloqueo`, y la API comprueba el estado bajo bloqueo. Los estados y el endpoint de aprobación anteriores se conservan para compatibilidad histórica, pero no condicionan la emisión nueva. Las referencias a aprobación en el resto de este documento describen el flujo anterior.

El docente asignado y el director de grupo pueden escribir para sus estudiantes cuando tienen `boletines.observaciones.gestionar`. La misma comprobación de grupo se aplica al acceso individual y al trabajo desde `/academico/boletines/gestion`. Esta pantalla presenta los estudiantes a la izquierda y sus observaciones a la derecha; el año en curso y el grupo único se seleccionan automáticamente. No pide asignatura, porque la observación corresponde al estudiante y período.

La ruta `GET /api/evaluacion/boletines/{matricula}` es una vista preliminar; la emisión oficial individual, el PDF privado y la publicación siguen el flujo separado descrito en `BOLETIN_EMISION.md`.

### Lectura por grupo y caché

La pantalla de trabajo utiliza `GET /api/boletines/observaciones/grupo` con `grupo_token` y `periodo_token`: una lectura para el grupo, no una por estudiante. Año y período aparecen en la cabecera; el período incluye su estado. Año y grupo se conservan mediante selectores públicos en la URL y memoria de la pestaña, sin almacenar información privada en `localStorage` ni `sessionStorage`.

El servidor almacena únicamente el contenido de las observaciones durante cinco minutos, separado por colegio, grupo, período, matrículas y revisión. Guardar o aprobar invalida la revisión del grupo. En cada lectura se vuelven a comprobar permisos, asignación docente, pertenencia de las matrículas y bloqueos del año/período; esos controles no se cachean.

Evaluación y boletines reutilizan la caché de React Query durante la navegación, con invalidación por cambios y la recuperación de tiempo real existente. Los eventos `bulletin-issues`, `bulletin-observations` y `bulletin-templates` actualizan únicamente sus familias de consultas: emitir un PDF no invalida el catálogo ni las observaciones. Las lecturas HTTP privadas siguen siendo `no-store`. F5 crea una conexión nueva y conserva su autenticación necesaria; no se reutilizan firmas de otro socket. Las pruebas usan datos sintéticos e incluyen un grupo de 25 estudiantes, retorno entre pantallas, F5, cierre de período y revocación de asignación/permisos.

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
Un período cerrado no admite cambios de observación; la eventual corrección histórica requiere un proceso autorizado. El año cerrado también bloquea la observación final.

Una observación aprobada puede incluirse en el snapshot de una emisión individual. Su estado `aprobada` se refiere al texto de la observación, **no** a la aprobación o publicación del boletín.

Migraciones: central `2026_10_14_000001_register_bulletin_permissions.php`; tenant `2026_10_14_000005_create_bulletin_observations.php` y `000006_grant_bulletin_permissions.php`. Se aplicaron el 14 de octubre de 2026 **solo a PostgreSQL local** (central y cinco colegios) tras respaldar cada base, verificar su catálogo y ensayar esquema nuevo y actualización de una restauración aislada. Respaldos: `C:\Users\Pedro\AppData\Local\Temp\colegio-eventos-20261014-193558-12855b480d38`; al estar en Temp no constituyen retención externa. La simulación `--pretend` de la migración de permisos no funciona por el ID devuelto por Spatie en una inserción simulada; el ensayo de migración real sobre la restauración sí pasó. No se ejecutaron las migraciones pendientes de admisiones ni se modificaron datos académicos existentes.

Prueba aislada: `php artisan test --filter='test_bulletin_(policy|observations)' tests/Feature/AcademicModulesTest.php`.
