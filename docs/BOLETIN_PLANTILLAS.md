# Editor de plantillas de boletín

Corte local: 30 de octubre de 2026. El editor V6 se incorpora en `/academico/boletines/plantillas`. El prototipo independiente sigue separado. La franja de simulación de dispositivos no forma parte del SaaS. La galería ocupa el viewport completo y tiene una sola cabecera morada con título, logo con enlace a inicio y menú del usuario. Al editar se oculta esa cabecera y el editor ocupa todo el viewport. El pie general del SaaS no aparece en ninguno de estos dos estados; los controles de páginas y zoom pertenecen al editor.

## Diseño

La galería muestra exclusivamente las plantillas guardadas en la base de datos del colegio. Sin registros muestra un estado vacío. La biblioteca de bases y diseños de referencia permanece separada como fuente del asistente y de las herramientas de diseño: nunca se presenta como si fueran plantillas creadas por el usuario. Abrir una propuesta o una hoja en blanco no crea un registro; se requiere Guardar o Publicar. El asistente recomienda mediante reglas locales: no usa API generativa ni genera cargos. Las propuestas incorporan los datos institucionales guardados. Las notas de la vista previa son ficticias; imprimir esa muestra no constituye una emisión oficial.

Los bloques se arrastran, redimensionan, bloquean, duplican y ordenan por capas. Marco, pie y paginación tienen formato y posición editables. Hay papel Carta, A4, Legal y A5, dos orientaciones y 35 familias tipográficas locales con sus licencias. Las tablas ofrecen columnas, bordes, agrupación y resultados por asignatura o área. En el lienzo muestran una o dos filas; al emitir se expanden con las asignaturas del estudiante. Las áreas requieren su cálculo habilitado en el SIEE.

Los campos se insertan con etiquetas legibles, como `[Nombre del estudiante]`. La representación interna `{{student}}` sirve únicamente al motor. El servidor enlaza nombre, grado, nivel, grupo, año, período, I.H., resultados efectivos del SIEE, indicadores HTML saneados, observación, asistencia y promoción vigente. La valoración visual utiliza categorías configuradas; el desempeño numérico utiliza los rangos de la escala seleccionada.

## Persistencia y autorización

- Catálogo: GET /api/boletines/plantillas, con paginación de 20 registros.
- Borradores: POST /api/boletines/plantillas y PUT /api/boletines/plantillas/{token}.
- Publicación inmutable: POST /api/boletines/plantillas/{token}/publicar. Historial: GET /api/boletines/plantillas/{token}/revisiones.
- Eliminación: DELETE /api/boletines/plantillas/{token}?version={version}. Requiere el mismo permiso y plan de gestión y la versión vigente. Registra auditoría y marca deleted_at: desaparece del catálogo y de las nuevas emisiones. Conserva las revisiones y las referencias de emisiones anteriores; sus snapshots y PDF permanecen disponibles.
- Imágenes privadas: POST /api/boletines/plantillas/archivos y GET /api/boletines/plantillas/archivos/{token}. PNG/JPEG/WebP de hasta 2 MB y 3000 píxeles por lado, con cuota y escáner compartidos. Hasta 15 referencias y 6 MB por diseño.

Cada ruta verifica sesión, colegio, permiso y plan. Editar exige boletines.plantillas.gestionar y boletines_personalizables. Se auditan las escrituras; una versión desactualizada devuelve 409. Los recursos usan tokens opacos; las claves del lienzo son de presentación y nunca IDs de tablas.

El marco del editor y React comparten origen. El marco aísla estilos, no constituye una barrera de autenticación. El host verifica origen y ventana de los mensajes y usa el cliente común con cookies/CSRF. No guarda credenciales ni expedientes en localStorage. Los cambios sin guardar usan la recuperación cifrada de borradores de trabajo, con vencimiento de 24 horas. Recuperar un borrador no publica una plantilla.

El aviso de edición pendiente aparece en el catálogo, fuera del editor, y ofrece Revisar borrador (vista previa visual del diseño actual) y Descartar borrador. Descartar cancela la notificación de edición pendiente, retira la copia cifrada y vuelve al catálogo sin guardar la plantilla. El botón Eliminar de cada tarjeta abre una confirmación propia del editor; cancelar no genera escrituras.

Con elementos seleccionados, Ver datos de prueba afecta únicamente esa selección. Sin selección, el control superior cambia toda la hoja. Estas opciones de visualización no se guardan como datos académicos. Shift agrega o quita elementos de la selección; arrastrar desde una zona vacía de la hoja crea un recuadro de selección. Agrupar conserva las posiciones relativas y Desagrupar permite separar bloques compuestos como encabezado, escala, observaciones y firmas. Las tablas mantienen su vínculo a los resultados académicos.

Los grupos se conservan mediante groupKey, una clave acotada de presentación. El logo separado usa source=school-logo y conserva el vínculo al logo institucional; no admite una URL proporcionada por el editor. headerPart y repeat mantienen las piezas del encabezado en las páginas de continuación. Guardar, publicar y las revisiones conservan estos metadatos dentro del diseño validado.

## Emisión

El selector de emisión muestra tarjetas con miniatura y vista previa de la revisión publicada, con filtros de período o final y paginación. Las tarjetas forman un carrusel horizontal con flechas, rueda, arrastre de ratón, desplazamiento táctil y teclado; la vista ampliada sustituye el selector en el mismo modal y al cerrar vuelve a él. El mismo componente se usa para rector y docente. No ofrece la opción virtual «Plantilla institucional base»: el catálogo devuelve únicamente registros guardados. `GET /api/boletines/plantillas?publicadas=1&ambito=periodo` filtra antes de paginar y toma nombre y configuración de la revisión publicada, aunque exista un borrador posterior.

La gestión ocupa el mismo ancho que el contenido general. Los filtros se alinean en una fila cuando el espacio lo permite y se adaptan en pantallas pequeñas. Sin grupo solo aparece un aviso traducido; no se montan tablas, selector ni acciones de emisión. El docente con un único grupo mantiene su selección automática.

Cada estudiante tiene un acceso a Historial, paginado y autorizado por matrícula. Generar con otra plantilla conserva el PDF y el snapshot anteriores como revisiones separadas; el historial permite descargarlas según los permisos existentes. Una emisión preparada sin PDF muestra su estado, pero no ofrece una descarga inexistente.

Los nuevos PDF se guardan en el disco privado tenant, con esta estructura relativa al backend: `storage/app/tenants/<colegio>_<tenant_id>/boletines/<año>/<grado>/<grupo>/<período>/Revisión_<número>_<nombre_de_la_plantilla>/<archivo>.pdf`. Las carpetas de revisión no llevan tokens ni IDs. Se utiliza el nombre actual guardado de la plantilla, también al guardar un nuevo título sin publicar otra revisión. Guardar o publicar un cambio de nombre sincroniza las carpetas de todos sus PDF, actualiza referencias y audita; conserva el snapshot, la revisión de diseño usada y los bytes del documento. Los bloqueos de plantilla y emisión coordinan las generaciones y los cambios de nombre.

Los archivos de alumnos con nombres distintos pueden compartir una carpeta de revisión y plantilla. Si también coincide el nombre del PDF, se añade un sufijo legible `Copia_2`, `Copia_3`, etc. a la carpeta para mantenerlos separados. La creación exclusiva del archivo impide sobreescrituras concurrentes. Ejemplo de nombre de PDF: `Álvarez_Arias_Salomé_Cuarto_período_Primero_01A_2026.pdf`. El nombre del PDF procede del snapshot: apellidos, nombres, período real (o Final), grado, grupo y año seleccionado. Los espacios se convierten en guiones bajos y se conservan acentos. No se exponen rutas del servidor en la API; la descarga autorizada entrega el mismo nombre mediante Content-Disposition UTF-8.

Para reorganizar PDF previos, ejecutar primero `php artisan storage:organize-bulletins --tenant=inmaculada`. Con `--apply` usa el mismo servicio de sincronización de nombres: copia un respaldo en `storage/app/private/bulletin-migration-backups`, verifica SHA-256 antes y después, actualiza la referencia con bloqueo y auditoría, y retira solo la copia antigua que ya no tenga referencias después del commit. Retira directorios antiguos únicamente cuando están vacíos, sin eliminación recursiva y verificando el límite de la carpeta de boletines del colegio. Es idempotente. El comando no cambia snapshots, calificaciones, permisos ni documentos emitidos y no elimina carpetas de Laravel. `storage/fonts` contiene caché tipográfica de PDF; `storage/framework`, cachés, sesiones y vistas; `storage/logs`, registros de funcionamiento. Estas carpetas son necesarias y no forman parte del archivo de boletines.

configuracion.canvas es presentación validada: hasta 12 hojas de diseño y 300 bloques. No acepta HTML ejecutable, URLs arbitrarias, notas del editor ni contexto de muestra. Los diseños anteriores mantienen su renderizador.

Una emisión nueva congela datos e imágenes en canvas_runtime, dentro del snapshot. Cambiar después notas, rangos, indicadores o imágenes no modifica ese documento. La aprobación del SIEE permanece separada de los rangos descriptivos. Continúan los requisitos existentes de observación, notas completas y promoción anual; no se agrega aprobación humana del PDF.

El motor Node incluido recibe JSON por entrada estándar, con ejecutable fijo, límite de memoria y tiempo. Dompdf genera el PDF sin acceso remoto, PHP ni JavaScript embebido. Límite de salida: 60 páginas. Las medidas de salto son conservadoras para evitar recortes; una muestra no comprueba los datos académicos de un estudiante.

## Despliegue

Node 20 o superior debe estar disponible para PHP; configurar BULLETIN_NODE_BINARY si es necesario. storage/fonts debe ser escribible para la caché de Dompdf y no se versiona. Fuentes, licencias y motor se distribuyen con el repositorio.

Tras respaldo y ensayo en staging, aplicar la migración tenant 2026_10_30_000001_add_performance_ranges_to_scales. Es aditiva: agrega escalas_valorativas.desempenos nullable y no altera notas ni asigna cortes. En local se respaldaron y verificaron las cinco bases antes de aplicarla. Respaldos fuera de los repositorios: .codex-publication/2026-10-30/database-backup del workspace.

Aplicar también la migración tenant 2026_10_30_000002_soft_delete_bulletin_templates para incorporar deleted_at antes de desplegar las acciones de eliminación. La migración solo añade una columna nullable; en local se verificó en los cinco colegios.

Los rangos se editan en el SIEE y en la escala: entre 2 y 8 bandas, cobertura completa, sin huecos ni solapamientos y con un decimal. Un año cerrado bloquea cambios. Sin rangos, una plantilla con desempeño numérico exige configurarlos antes de emitir: no toma los umbrales ficticios de la muestra.

Las categorías sin imagen propia usan un atlas local de glifos Noto Emoji (OFL) para que el PDF no dependa del soporte de emoji del sistema. Las imágenes configuradas tienen prioridad. Los glifos de reserva son monocromos. Las caras tipográficas del PDF se distribuyen como instancias regulares, negritas y oblicuas derivadas, con sus licencias. Los scripts prepare-bulletin-fonts.py y prepare-bulletin-emoji.py son herramientas de desarrollo (fonttools/Brotli/Pillow), no dependencias Python del servidor. PHP requiere la extensión ZIP para el atlas.

## Distribución y peso de los recursos

El motor puro tiene una fuente canónica en resources/bulletin-editor del backend: ocho módulos JavaScript, versionados con engine-manifest.json y SHA-256. La copia pública del frontend es un artefacto de distribución necesario para ejecutar el editor en el navegador, no otra implementación que deba editarse manualmente. El PDF continúa siendo autónomo en el servidor; no descarga código del frontend al emitir.

Para actualizar el motor, editar el backend, ejecutar `node scripts/release-bulletin-engine.cjs <nueva-version>` y, en el frontend, `npm run sync:bulletin-engine`. Revisar y versionar las dos distribuciones juntas. `npm run check:bulletin-engine`, las pruebas y los pasos previos a dev/build detectan cambios sin manifestar y diferencias entre repositorios cuando ambos están disponibles. Un checkout independiente del frontend verifica su propia distribución fijada y no requiere el backend para compilar. Puede usarse `--source <directorio>` al sincronizar desde otra ubicación.

Las 33 familias locales del navegador se distribuyen como WOFF2 con compresión sin recortar glifos, pesos variables ni métricas. Sus licencias se conservan. El backend contiene las cuatro caras TTF que Dompdf necesita por familia, sin las copias originales de las fuentes web ni los iconos exclusivos de la interfaz. La fuente estática de desarrollo para regenerar el atlas de emojis y sus licencias se conservan; el atlas funciona sin red.

Para preparar de nuevo las caras PDF: `python scripts/prepare-bulletin-fonts.py --web-fonts <frontend>/public/bulletin-editor/assets/fonts`. La opción `--output <directorio>` permite verificarlas fuera del runtime y `--family <nombre>` limita una prueba. Para regenerar las fuentes WOFF2 desde los originales, el frontend incluye scripts/prepare-bulletin-web-fonts.py con `--source <directorio-original-assets/fonts>` y scripts/bulletin-font-requirements.txt. Este proceso comprueba orden de glifos, mapa Unicode y métricas después de comprimir. Las fuentes originales ya no son una dependencia del despliegue.

Medición local de las carpetas del editor (bytes sin comprimir): frontend 20.407.618 → 8.808.921; backend 85.060.908 → 63.168.712. La diferencia es aproximadamente 33,5 MB (31,8 %). El presupuesto del build incluye ahora toda la carpeta pública del editor, con límite de 10 MB, además de los presupuestos de JS/CSS de entrada. Los respaldos de los recursos anteriores están fuera de ambos repositorios, en .codex-publication/2026-10-30/optimization-originals del workspace.


## Biblioteca de plantillas y acomodo del lienzo

El panel Plantillas muestra miniaturas del catálogo de bases, con En blanco primero. Son propuestas del sistema, no plantillas creadas por el colegio. Las miniaturas se dibujan al entrar en pantalla y se reutilizan durante la edición.

Al seleccionar una base se elige entre reemplazar la página actual y añadir sus páginas a continuación. La inserción conserva las páginas anteriores, el identificador del documento y sus imágenes, asigna identificadores nuevos a los bloques/grupos insertados y puede deshacerse. Cada página conserva el tamaño y la orientación de la base elegida. No se bloquea la inserción por el tipo de diseño: los campos académicos siguen el contexto autorizado que se elija al emitir, independientemente de la base visual. El aspecto de encabezados, tablas y firmas insertados se conserva por bloque mediante appearance validado en JS y PHP.

El motor 1.5.0 conserva la geometría libre y los solapamientos intencionales. Cuando crece un bloque, desplaza los bloques que estaban apilados debajo y compartían su ancho, tanto en los datos de prueba del lienzo como al emitir. Las tablas anuales usan campos compactos P1, P2, etc. en las filas representativas; la vista previa y la emisión desarrollan todas las filas y continúan en las páginas necesarias antes de colocar promoción y firmas. Los datos de prueba siguen siendo transitorios y no sustituyen valores académicos.


## Páginas consecutivas y formatos mixtos (motor 1.6.0)

El lienzo muestra las páginas en una columna continua. Sobre cada una hay título opcional, subir/bajar, ocultar/mostrar, bloquear/desbloquear, duplicar, eliminar y añadir inmediatamente después. Los títulos son metadatos del editor y no se imprimen. Duplicar remapea identificadores de bloques y grupos; ordenar mueve la página completa; estas acciones entran en Deshacer.

Una página oculta sigue visible al editar, pero no entra en la vista previa, la impresión ni el PDF oficial. La numeración pública se recalcula sin ella. Una página bloqueada impide editar contenido, cambiar papel, reemplazarla y eliminarla hasta desbloquearla. Todas estas propiedades se validan y conservan al guardar y publicar revisiones; no añaden nuevas API ni cambian permisos académicos.

El selector permite abrir una vista previa ampliada antes de reemplazar o añadir. Las miniaturas son mayores y los paneles se cierran al repetir su herramienta o pulsar Ocultar panel.

Las continuaciones usan la geometría de su página de origen. La impresión del navegador usa reglas de página por hoja. Para el PDF oficial, Dompdf se configura con CPDF: se compone en un contenedor común y se aplica MediaBox y traslación vertical a cada página. Las pruebas inspeccionan dimensiones físicas y posiciones; deben ejecutarse al actualizar Dompdf porque ese paso usa su adaptador CPDF. Una plantilla con todas las páginas ocultas se puede conservar como borrador, pero no genera un boletín vacío.

El motor 1.6.2 fija también los anchos proporcionales en las celdas y encabezados de las tablas: Dompdf no respeta por sí solo el ancho del colgroup. Esto evita que columnas iguales recorten indicadores que el distribuidor midió usando el ancho configurado. Se incluyen las columnas expandidas de los períodos del informe anual. La medición PDF reserva además la sangría y los márgenes de listas y párrafos de los indicadores para que sus últimas líneas permanezcan dentro del bloque.

La preferencia design.previewScale (numeric o visual) conserva el tipo de muestra al guardar y volver a abrir una plantilla. Es un metadato de presentación; no persiste estudiantes ni notas ficticias y no decide la valoración de una emisión real, que procede del SIEE y de la escala del grupo.

La distribución 1.6.3 normaliza los ocho módulos canónicos a LF antes de calcular sus huellas. El frontend fija también LF en Git para esos scripts y sus manifiestos. Se comprueban tanto los archivos de trabajo como los blobs preparados para Git, evitando diferencias entre el checkout de Windows y el despliegue en Linux; el comportamiento del motor no cambia.
