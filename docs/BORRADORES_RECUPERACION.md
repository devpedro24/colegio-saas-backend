# Recuperación privada de trabajo pendiente

Implementación del 25 de octubre de 2026. Un borrador conserva trabajo sin confirmar; nunca sustituye Guardar, Enviar, Publicar o Emitir. No modifica notas, asistencia, indicadores, observaciones ni entregas oficiales, y sus escrituras no invalidan las cachés académicas ni generan sus eventos de actualización.

## Flujo y cobertura

La interfaz conserva una copia local mientras se edita y agrupa el respaldo privado al servidor aproximadamente cada 10 segundos. Al regresar ofrece continuar, revisar el contenido o descartarlo. «Mis borradores» reúne las copias pendientes. Los textos están traducidos a español e inglés.

Están conectados: notas de planilla y resultados manuales, actividades de evaluación, asistencia pendiente, indicadores por grupo y grado, observaciones de boletines, formularios de nivelación, contenido y secciones del Aula, preguntas, respuestas de tareas y retroalimentación docente. El respaldo de archivos está conectado al contenido del Aula y las respuestas de tareas. Esta capa es reutilizable; un formulario adicional necesita integrarse explícitamente con `useWorkDraft`, y los cuestionarios cronometrados conservan su propio flujo.

La última ruta de trabajo y los filtros admitidos se conservan cifrados. Después de iniciar sesión, un destino explícito válido tiene prioridad; en otro caso se recupera la última ruta. Solo se aceptan rutas internas y parámetros permitidos, nunca URL externas ni parámetros de credenciales. La ruta de la pestaña visible tiene prioridad sobre las pestañas en segundo plano.

## Retención, conflictos y autorización

- Caducidad: 24 horas desde la edición real, usando el reloj del servidor como referencia. Consultar, restaurar o sincronizar la misma copia no renueva el plazo; editarla sí.
- Guardar correctamente o descartar elimina la copia de recuperación y deja una marca temporal de descarte para impedir que una pestaña antigua la reactive.
- Las pestañas mantienen ramas independientes. Una versión desactualizada nunca sobrescribe silenciosamente otra copia. El reintento de una petición cuya respuesta se perdió es idempotente.
- Antes de restaurar para edición se revisan la versión del registro, el permiso actual y las reglas del formulario. Un período cerrado, una fecha de asistencia pasada o un plazo vencido siguen bloqueando la modificación normal. La revisión del borrador no concede permiso para guardar.
- El backend vuelve a autorizar cada operación oficial. Las entregas del Aula envían su versión esperada y rechazan una respuesta antigua con HTTP 409.

## Protección de datos

El contenido local se almacena en IndexedDB cifrado con AES-GCM de 256 bits, IV aleatorio por escritura y autenticación del ámbito y token del borrador. La clave importada no es exportable y solo permanece en memoria durante la sesión. No se guardan claves, contenido personal ni credenciales en localStorage/sessionStorage.

El servidor conserva el contenido textual cifrado y la clave de recuperación protegida con Laravel Crypt. La entrega de la clave exige autenticación, usa respuestas `private, no-store` y está aislada por usuario y base de datos del colegio. No es cifrado de extremo a extremo: el servidor autenticado puede recuperar la clave. En producción se requiere HTTPS, además de la protección de sesión ya existente. Un navegador o dispositivo comprometido durante una sesión activa queda fuera de esta protección.

Las API usan tokens opacos de 24 caracteres, cookies y CSRF del cliente compartido. El acceso a borradores ajenos responde 404. Cambiar de identidad elimina la clave y el contenido descifrado en memoria. Los registros de petición no incluyen cuerpos ni claves.

## Archivos y límites

Los adjuntos se copian cifrados localmente y se respaldan mediante el almacenamiento privado del servidor, con validación de MIME, tamaño, cuota y escáner existentes. El archivo temporal no queda vinculado a un recurso publicado ni constituye una entrega. Las descargas requieren propietario, pertenencia, permiso actual y vigencia; no hay enlaces públicos.

Límites por propietario: 100 borradores activos, 8 MB de contenido cifrado total y 700.000 caracteres cifrados por copia; los archivos del servidor tienen un máximo de 50 archivos/100 MB y el límite individual configurado del sistema (25 MB actualmente). El formulario puede referenciar hasta 30 archivos. La cuota local de adjuntos es de 140 MiB de contenido cifrado aproximado.

Un archivo necesita terminar su copia para poder recuperarse. Si falla el almacenamiento o respaldo, la interfaz muestra una advertencia. Sin conexión se conserva la copia local disponible y se reintenta el respaldo con espera creciente. Tras cerrar la sesión, recuperar la clave requiere volver a autenticarse con conexión. No se garantiza recuperar la última escritura interrumpida ni datos locales borrados por el navegador; una copia ya respaldada en el servidor permite recuperarlos al iniciar sesión.

Los archivos temporales compartidos por ramas de un borrador se conservan hasta caducar incluso si se guardó o descartó una rama. La sincronización de una edición real extiende su vigencia hasta el vencimiento de esa edición. La limpieza conserva los registros cuyo archivo físico no se haya podido eliminar para reintentarlo.

## Despliegue y operación

Aplicar la migración central `2026_10_25_000001_create_work_drafts` y las migraciones tenant `000001_create_work_drafts`, `000002_create_work_draft_files` y `000003_add_resource_to_work_draft_files` del mismo día. Usar el procedimiento del proyecto para `php artisan migrate` y `php artisan tenants:migrate`; respaldar las bases antes de migraciones en producción.

`php artisan drafts:prune` limpia registros vencidos centrales y de cada colegio. Está programado cada hora sin solapamiento; requiere el scheduler Laravel activo. La expiración se comprueba al leer, aunque la eliminación física ocurra en la siguiente ejecución. Las migraciones fueron aplicadas en el entorno local y se verificó su scheduler.

## Comprobaciones

Pruebas automáticas de cifrado/autenticación, aislamiento entre usuarios y colegios, TTL, reintentos idempotentes, conflictos, descartes, limpieza, adjuntos privados, plazos y versiones de entregas. Prueba de navegador como docente: recuperar una nota pendiente tras recargar y recuperar título, HTML y archivo de Aula. Ninguna de esas pruebas manuales confirma notas ni publica recursos.
