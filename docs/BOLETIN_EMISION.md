# Emisión individual de boletines — incremento local (14-10-2026)

## Flujo vigente desde el 23 de octubre de 2026

- `POST /boletines/emisiones/{matriculaToken}/emitir` recibe la misma selección de período/final y plantilla, requiere `boletines.emisiones.preparar`, prepara el snapshot y genera directamente el PDF privado. No requiere aprobar previamente la observación ni una aprobación humana del PDF. Los estados internos históricos se conservan: `aprobado` significa PDF generado en la interfaz nueva.
- Publicar sigue siendo una acción de visibilidad independiente con `boletines.emisiones.publicar`; generar no expone automáticamente el documento al estudiante.
- Docente y director de grupo tienen celdas configurables, activadas por defecto, para consultar plantillas, consultar documentos, generar y publicar. Toda lectura y escritura de emisiones verifica su grupo asignado/dirigido, incluso al descargar por token.
- `/academico/boletines/gestion` tiene pestañas de observaciones y boletines. Permite seleccionar algunos o todos los estudiantes del grupo y una plantilla publicada o base. Ejecuta peticiones individuales secuenciales, muestra el resultado/error de cada estudiante y no repite revisiones idénticas. No es un trabajo en segundo plano: se debe mantener la página abierta.
- Se puede emitir con el **período abierto o cerrado**, siempre con resultados completos, observación guardada y los permisos correspondientes. Un período planificado no admite emisión. Cerrar bloquea cambios ordinarios, no la generación del documento con los datos ya guardados. El boletín final anual mantiene sus requisitos de cierre y promoción vigente.
- Las escrituras de emisiones toman primero el bloqueo del año, igual que notas y recuperaciones, para congelar insumos consistentes. Si las notas cambian durante el período abierto, la siguiente emisión crea una revisión nueva; nunca sobrescribe el PDF anterior. Las descripciones de aprobación humana a continuación son históricas.

Migraciones locales aplicadas: central `2026_10_23_000002_allow_scoped_teacher_bulletin_issuance` y tenant `2026_10_23_000002_grant_scoped_teacher_bulletin_issuance`, en los cinco tenants. Solo cambian permisos; no emiten documentos ni alteran notas.

La vista de notas sigue siendo **preliminar**. La emisión oficial es otra operación: selecciona un estudiante y un período cerrado (o el final de año cerrado), valida resultados completos y observación aprobada, prepara una instantánea, aprueba un PDF privado y solo después lo publica al estudiante. No se hicieron emisiones en colegios reales durante la implementación; las pruebas usan bases efímeras y estudiantes sintéticos.

## Contrato

Todas las rutas requieren sesión del colegio y `?opaque=1`, con selectores opacos de matrícula, período, plantilla y emisión.

| Acción | Ruta relativa a `/api` | Permiso |
| --- | --- | --- |
| Histórico paginado | `GET /boletines/emisiones/{matricula}` | `boletines.emisiones.ver` o estudiante titular (solo publicados) |
| Comprobar requisitos sin guardar | `GET /boletines/emisiones/{matricula}/preparacion` | `boletines.emisiones.preparar` |
| Preparar revisión | `POST /boletines/emisiones/{matricula}/preparar` | `boletines.emisiones.preparar` |
| Aprobar y archivar PDF | `POST /boletines/emisiones/documentos/{emision}/aprobar` | `boletines.emisiones.aprobar` |
| Publicar al estudiante | `POST /boletines/emisiones/documentos/{emision}/publicar` | `boletines.emisiones.publicar` |
| Descargar PDF | `GET /boletines/emisiones/documentos/{emision}/pdf` | `boletines.emisiones.ver`, o estudiante titular y publicado |

Preparación recibe `contexto=periodo` con `periodo_token`, o `contexto=final`, y `plantilla_token` opcional. Sin plantilla se utiliza la base institucional; la personalización exige capacidad del plan y revisión publicada. Una preparación igual no duplica la revisión. Si cambian insumos o política, aprobar/publicar devuelve conflicto y exige una revisión nueva. Un preparado anterior se descarta; un documento publicado anterior se sustituye solo al publicar el nuevo. El PDF anterior permanece archivado y accesible al personal autorizado. El estudiante solo ve la versión actualmente publicada.

La instantánea conserva institución, alumno, resultados efectivos por materia/área, indicadores visibles, observación aprobada, asistencia agregada y decisión de promoción vigente para el final. No confunde nota pendiente con cero. El PDF se genera del lado del servidor, sin recursos remotos ni PHP embebido, con diseño de la revisión seleccionada. Se almacena en el disco privado del tenant y se verifica SHA-256 al descargar; el acceso emplea `Cache-Control: private, no-store` y se audita. La aprobación, publicación y lectura quedan en auditoría. La plantilla de diseño no ejecuta HTML arbitrario.

## Migraciones y evidencia local

- Central: `2026_10_14_000004_register_bulletin_issue_permissions.php`.
- Tenant: `2026_10_14_000011_create_bulletin_issues.php` y `2026_10_14_000012_grant_bulletin_issue_permissions.php`.
- Ensayo PostgreSQL 16: migración limpia y restauración de Inmaculada pasaron sin alterar firmas de usuarios, matrículas, correo ni notas. Respaldo verificado temporal: `C:\Users\Pedro\AppData\Local\Temp\colegio-eventos-20261014-211123-5e07aa32ff92`. No es retención permanente.
- Aplicadas a central y cinco tenants locales. Ningún script de esta entrega emitió boletines ni envió correos.
- Pruebas aisladas: períodos abiertos/incompletos, observación obligatoria, idempotencia, preparación desactualizada, PDF privado, publicación, descarga del titular e integridad del archivo. `composer audit --locked` sin alertas conocidas para la versión instalada.

## Límites abiertos

- La plantilla viva del editor usa datos ficticios; el PDF oficial usa datos reales congelados. Aún falta paridad visual completa entre la preview del editor y el PDF, además de diseñador de texto enriquecido/Canva.
- No hay firma digital certificada, confirmación digital de recepción por estudiante, portal de acudientes, correos de boletín ni regla integrada de paz y salvo.
- Asistencia se agrega en totales, no por materia. No se agregan número/foto del documento estudiantil ni observación por asignatura porque no hay contrato completo de esos datos en la emisión.
- El PDF archivado no se regenera automáticamente si se pierde: se señala error de integridad/disponibilidad para evitar reconstruirlo con un logo u otros activos posteriores y aparentar fidelidad histórica.
- La corrección histórica de resultados de un año cerrado y sus aprobaciones requiere un proceso separado. No se reabren datos ni se alteran documentos publicados silenciosamente.
- La emisión final exige promoción calculada, aprobada y vigente, y observación final adicional solo si la política del colegio la requiere. No contempla un consejo que cambie el resultado académico.
