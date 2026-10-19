# Eventos y tareas del Aula — entrega del 14 de octubre de 2026

Implementación local. No certifica entrega a buzones ni despliegue de infraestructura de producción.

## Contrato funcional

- Eventos manuales: actividad, reunión, celebración y aviso. La API rechaza `tarea`.
- Crear/guardar deja un borrador privado únicamente al autor. Publicar fija `publicado_en`; repetir la publicación no crea correos adicionales. Editar no cambia esa fecha.
- Publicar para grupos requiere permiso y asignación docente vigente; el antiguo interruptor que permitía cualquier grupo ya no amplía acceso. Publicación institucional requiere `eventos.publicar_institucional`, independiente de gestionar.
- «Enviar evento por correo al publicar» comienza apagado. El alcance, la asignatura y esta decisión quedan fijados al publicar. Editar título, descripción o fecha no reenvía notificaciones. Avisos posteriores de cambios/cancelación son una etapa futura explícita.
- Tareas: proyección de `aula_recursos`, no copias en `eventos`. Con límite, se ubican en la fecha de entrega; sin límite, en publicación efectiva. Se usa la zona guardada por el Aula, con zona institucional como respaldo. Borradores, secciones ocultas, recursos archivados/eliminados y publicaciones futuras no aparecen. Se comprueban plan Aula y permisos propios del Aula, no permisos de Eventos.
- La primera publicación se conserva al editar. Mostrar una sección antes oculta publica los recursos visibles correspondientes. Una programación todavía futura se puede cambiar/cancelar. La copia de un año no hereda publicaciones.

## API

Todas las rutas requieren sesión, colegio, autorización y selectores opacos. Las nuevas respuestas son presentadores explícitos, sin IDs internos ni direcciones de destinatarios.

| Ruta | Función |
| --- | --- |
| `GET /api/eventos` | Eventos visibles, paginados, filtros desde/hasta/grupo_token. |
| `GET /api/eventos/resumen` | Solo conteos, categorías, estados y título si hay exactamente uno por fecha; no descarga el mes entero. |
| `GET /api/eventos/flujo` | Primeros 50 eventos visibles y siguientes bloques de 10 con cursor estable; admite filtro de día, grupo y tipo de evento. |
| `GET /api/eventos/tareas` | Tareas publicadas del Aula; mismo intervalo máximo de 93 días, 200 por página. |
| `POST /api/eventos` | Crear borrador; `enviar_correo` opcional y apagado por defecto. |
| `PUT /api/eventos/{token}` | Editar; el cliente envía `version` para detectar cambios concurrentes. |
| `POST /api/eventos/{token}/publicar` | Publicar con `version`; transacción de publicación y destinatarios. |
| `GET /api/eventos/{token}` | Detalle/adjuntos; solo editores reciben conteos de correos. |
| `POST /api/eventos/{token}/correos/reintentar` | Reencolar exclusivamente los fallidos de este evento, con autorización y auditoría. |

## Correo y colas

`evento_correos` es la bandeja de salida durable por estudiante. La clave evento + estudiante impide duplicar la única publicación actualmente permitida. Los destinatarios se calculan en backend: usuario estudiante activo, matrícula activa, grupo activo y año no cerrado/archivado, dentro del alcance. Se excluyen docentes, otras funciones de personal y representantes; al enviar se comprueba nuevamente matrícula/estado y se consulta el correo vigente.

Se cifra el correo almacenado y la instantánea de contenido. El payload del job contiene solo la clave del colegio, no correos ni contraseñas. La plantilla HTML + texto ES/EN incluye colegio, título, fecha/hora/zona, descripción escapada y enlace al evento. Los adjuntos se consultan autenticados, no se anexan al correo. El enlace conserva el selector del evento al pasar por el login de correo/contraseña.

Para eventos se exige transporte directo de entrega (SMTP u otro proveedor soportado), o la conexión institucional; no se acepta `log`, `array` ni un agregado/failover que pueda terminar registrando el mensaje sin entregarlo al proveedor. El socket SMTP tiene timeout de 10 segundos; el lote deja de iniciar envíos tras 120 segundos.

- Cola: `event-mail`, conexión `event_mail` (database central), separada de matrículas y realtime.
- Lotes de hasta 10, máximo un lote por colegio cada 60 segundos. `EVENT_MAIL_BATCH_SIZE` puede reducirlo; `EVENT_MAIL_BATCH_INTERVAL` puede aumentar la espera. Un lock compartido impide concurrencia entre workers del mismo colegio.
- Reintentos de transporte: máximo 5, esperas 60/300/1800/7200 segundos antes de los intentos siguientes. Después queda `fallido`, visible para revisión/reintento autorizado. Una dirección inválida requiere corregir el usuario antes de reintentar.
- Estados: pendiente, enviando, enviado (aceptado por SMTP), fallido, cancelado. La pantalla actualiza conteos cada 15 segundos mientras hay pendientes.
- Eliminar el evento cancela los pendientes/fallidos; un destinatario que pierde alcance se cancela al procesar. No se puede retirar un correo ya aceptado por el proveedor ni uno en tránsito.
- Un proceso interrumpido deja `enviando`; tras cinco minutos se recupera. Si SMTP aceptó el mensaje y el proceso cayó antes de guardar el resultado, podría repetirse: SMTP no ofrece atomicidad con la BD. No se promete «exactamente una vez» en ese caso.

## Operación necesaria

La publicación NO envía en la petición HTTP: conserva la intención y el despachador la encuentra después del commit. Deben ejecutarse ambos procesos, supervisados y con reinicio ante fallos:

```sh
# Cada minuto, a través del scheduler normal de Laravel:
php artisan schedule:run

# Worker dedicado, proceso persistente:
php artisan queue:work event_mail --queue=event-mail --sleep=3 --timeout=180 --tries=3

# Diagnóstico puntual de despacho (no sustituye el scheduler):
php artisan eventos:enviar-correos --tenant=inmaculada
```

La conexión tiene `retry_after=300`, superior al timeout de 180 y al lock de 240. Si se cambia de backend de cola, conservar esta relación y usar caché compartida entre hosts. Monitorear proceso, antigüedad de pendientes, `failed_jobs` y fallidos por destinatario; no reintentar masivamente la cola general. La entrega real depende de configuración institucional, cuotas/reputación del proveedor, DNS y buzones. Aceptación SMTP no confirma bandeja; rebotes/webhooks, alertas operativas externas y pruebas con buzones controlados siguen siendo verificación de despliegue.

La primera entrega no instaló procesos persistentes ni envió correos reales. La activación local posterior y la prueba individual autorizada se detallan a continuación; no se enviaron avisos a estudiantes.

### Activación local en Windows — 14 de octubre de 2026

Se instaló la tarea de Windows `ColegioSaaS-Eventos-99A7559F8AE5`, limitada al usuario actual, sin guardar contraseñas. Arranca una vez al iniciar sesión mediante `wscript.exe`, que ejecuta el supervisor sin abrir una consola de PowerShell. Se retiró el disparador que relanzaba la tarea cada minuto y los reinicios automáticos de la tarea: al cerrarla ya no reaparece sola durante la sesión. Una exclusión por workspace evita duplicados. El supervisor mantiene ocultos:

- `artisan schedule:work`: ejecuta el programador normal, incluido el despachador de eventos cada minuto.
- `artisan queue:listen event_mail --queue=event-mail --sleep=3 --timeout=180 --tries=3`: exclusivamente eventos, sin consumir `default` ni `realtime`. En Windows, `queue:listen` controla el timeout del proceso hijo con Symfony; no depende de `pcntl`. Cada trabajo carga el código vigente.

El supervisor recupera un proceso terminado cada cinco segundos. Se comprobó deteniendo únicamente el listener dedicado mientras la cola estaba vacía: reapareció con otro PID y continuó activo. El scheduler completó el despacho periódico sin errores. El lanzador `start-all.bat` reutiliza estos procesos en vez de abrir otro scheduler.

```powershell
# Desde el backend: consultar, iniciar, detener o reinstalar en este equipo.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/event-services.ps1 -Action Status
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/event-services.ps1 -Action Start
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/event-services.ps1 -Action Stop
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/event-services.ps1 -Action Install
```

`Stop` deshabilita el arranque automático y solicita detener el supervisor y sus procesos. Un trabajo hijo ya iniciado puede terminar; no retira un correo aceptado por SMTP. `Start` vuelve a habilitar la tarea. Si el supervisor se cierra inesperadamente, hace falta `Start` o volver a iniciar sesión; el supervisor sí reinicia sus procesos hijos mientras esté activo. Los registros y el heartbeat están en `storage/logs/event-runtime/`, excluido de Git. Los logs de cada proceso se reinician cuando este vuelve a arrancar. No se detienen otros servicios del proyecto.

**Límite operativo:** es una instalación local para la sesión de Windows de Pedro; requiere equipo encendido, sesión iniciada, PostgreSQL y conexión de red. No funciona con el equipo apagado/suspendido ni sustituye un servicio 24/7 de producción. El scheduler también ejecuta sus tareas académicas ya registradas. No se habilitó un worker de la cola general.

### Prueba real de correo, a un solo destinatario

Antes del arranque se confirmó cero trabajos y cero destinatarios pendientes de eventos en todos los colegios activos. Se envió una única prueba al buzón expresamente indicado por el propietario, con asunto `Nuevo evento del colegio: [PRUEBA] Presentación del calendario escolar`. No se guardó esa dirección en el código ni en este documento.

El job `SendEventMailPreview` pasa por la misma conexión/cola `event_mail`/`event-mail`, el transporte institucional de Inmaculada y la plantilla real HTML/texto de eventos. Su payload está cifrado; no crea eventos, matrículas, estudiantes ni registros de envío masivo. Tiene un único intento automático para evitar repetir la prueba ante una aceptación SMTP ambigua. El botón abre el calendario local; no hay evento ficticio publicado.

Resultado: el worker completó el envío el **14 de octubre de 2026 a las 13:07:29, America/Bogota**; SMTP lo aceptó para un destinatario, quedaron cero trabajos/fallos de eventos y las bandejas de destinatarios de los colegios siguieron vacías. El comprobante temporal es `a2b2ddc318e390830053ac93` (caduca a las 24 horas). **No se ha verificado la llegada a bandeja ni su renderizado dentro de Gmail**: requiere confirmación del destinatario. El recorrido visual local de la plantilla en escritorio/móvil y ES/EN sí pasó.

**Confirmación posterior del destinatario (14 de octubre):** informó que recibió el correo y aprobó el diseño, solicitando únicamente ocultar `America/Bogota`. Se retiró la etiqueta de zona horaria en HTML y texto plano, tanto en español como en inglés; se mantienen fecha/hora y la zona institucional interna. No se reenvió la prueba. Esta confirmación corresponde a un único buzón, no a una certificación de entrega masiva.

Diagnóstico local reutilizable, únicamente con autorización expresa de un destinatario de prueba:

```sh
php tests/Support/event-mail-runtime.php --inspect
php tests/Support/event-mail-runtime.php --enqueue --tenant=inmaculada --to=DESTINATARIO_AUTORIZADO
php tests/Support/event-mail-runtime.php --status=COMPROBANTE
```

La opción `--enqueue` efectúa un envío nuevo: no ejecutarla repetidamente para consultar el estado. La consulta de estado no envía correo. Pruebas posteriores a esta activación: 15 casos del módulo aprobados (108 aserciones), incluyendo destinatario único, ausencia de registros ficticios, protección contra repetición tras aceptación y fallo de transporte sin falsa confirmación; recorrido `test:ui:events` aprobado.

## Agenda local de ejemplo (14 de octubre de 2026)

Se cargaron **320 eventos simulados** en la base local de Inmaculada, publicados y repartidos entre los días hábiles del año lectivo 2026 (19 de enero a 27 de noviembre): 140 actividades, 80 reuniones, 40 celebraciones y 60 avisos. Treinta son institucionales y 290 están asignados a grupos activos; en octubre aparecen 22. Los títulos no llevan una marca de prueba para que el calendario se pueda revisar con contenido verosímil, pero **no representan eventos oficiales confirmados por el colegio**.

Los 320 tienen `enviar_correo=false`; después de cargarlos se verificaron **0 registros en `evento_correos`** y **0 eventos con correo habilitado**. No se envió ningún aviso a estudiantes. El cargador [`tests/Support/seed-local-events.php`](../tests/Support/seed-local-events.php) solo permite `APP_ENV=local`, exige un año 2026 activo y rector autorizado, no modifica eventos existentes y aborta si ya hay alguno, para evitar duplicaciones. Es deliberadamente una carga única para este entorno; no se ejecuta en despliegues ni migraciones.

```sh
php tests/Support/seed-local-events.php --inspect
# Solo en una base local vacía y con decisión explícita de cargar ejemplos:
php tests/Support/seed-local-events.php --apply
```

La pantalla de Eventos mantiene la fecha actual al crear; el tipo arranca sin seleccionar y es obligatorio. Las categorías conservan el código cromático del calendario y se distinguen también en las tarjetas, el listado y el detalle. El encabezado usa la misma tarjeta blanca del resto del módulo académico.

### Agenda densa para revisar rendimiento

Una segunda carga local añadió **698 eventos publicados y 12 borradores de María**. El entorno contiene ahora 1018 publicados y 12 borradores. Octubre tiene 720 publicados distribuidos en sus 22 días hábiles: mínimo 20 por día y **300 el 14 de octubre**. Los borradores solo aparecen al creador; usan una marca ámbar diferenciada y nunca se envían por correo. La carga dejó `enviar_correo=true` en cero y `evento_correos` en cero. Estos registros son material simulado de interfaz/rendimiento, no programación oficial del colegio.

`tests/Support/seed-dense-local-events.php --inspect` informa cuánto falta; `--apply` completa únicamente los mínimos de octubre y los borradores identificables, sin alterar registros existentes. Ambas operaciones están restringidas a `APP_ENV=local` y al rector María con permisos válidos. Repetir `--apply` tras completar las metas no crea publicaciones duplicadas.

### Estrategia de carga y caché

El calendario consume el resumen compacto por día; el panel derecho recibe primero **50** eventos, después **10** por clic en «Cargar más». El botón «Ver todos los eventos» y su vista paralela se retiraron. Los filtros de grupo y tipo (actividad, reunión, celebración, aviso y tarea) afectan por igual a los conteos/puntos del calendario y a la lista. Un cursor evita relecturas y saltos por desplazamiento profundo. La interfaz conserva el orden recibido del servidor cuando varios eventos tienen la misma fecha de creación; no desempata con el token opaco, que no representa el orden de la base de datos. Las tareas siguen consultándose mediante su proyección del Aula, sin copiarse a la tabla de eventos; al escoger un tipo distinto de tarea se omite esa consulta. Cambiar de mes y volver reutiliza las páginas en memoria de React Query durante la sesión, con invalidación cuando llega un cambio del colegio. El panel derecho sin día seleccionado iguala la altura del calendario y desplaza internamente sus tarjetas; al filtrar un día puede reducirse a su contenido, con límite para días muy cargados.

La API guarda resumen y páginas durante hasta diez minutos en la **caché compartida de Laravel**, con clave segregada por colegio, usuario, permisos, grupos, filtros y revisión de datos. Las escrituras relevantes incrementan la revisión después del commit; la caché antigua caduca sola. En esta instalación `CACHE_STORE=database`: no hay Redis activo. La misma implementación admite `CACHE_STORE=redis` cuando exista un servidor Redis supervisado y se configure la extensión/cliente; no se cambió la infraestructura local ni se asumió que Redis estuviera disponible.

La migración aditiva `2026_10_14_000004_index_event_calendar_queries.php` indexa fecha/estado/categoría, cursor de creación y búsqueda por grupo. Se aplicó solamente a la base local de Inmaculada tras revisar el SQL con `--pretend`; deberá ejecutarse también para los demás tenants durante el despliegue de esta versión.

Comprobación local: la primera lectura del resumen de octubre realizó una consulta a `eventos`; repetir la misma lectura con la misma identidad/filtros realizó **cero** consultas a `eventos`. El flujo real devolvió 50 registros y el cursor siguiente 10 sin solapamiento. La suite de eventos y el recorrido de navegador comprueban un día de 300 eventos, contador de sobrantes, altura del panel, cambio de mes sin recarga y que los diez eventos nuevos aparezcan después de los 50 previos incluso con fechas de creación idénticas.

Al pulsar **F5**, el navegador inicia un cliente nuevo y realiza una lectura autenticada y limitada (resumen y primer bloque de 50); la caché compartida evita recalcular una agenda sin cambios. No se persisten listados privados ni borradores en almacenamiento del navegador: la política general `no-store` evita que otro usuario del mismo equipo vea datos de una sesión previa. Por esa razón no se promete literalmente «cero solicitudes HTTP tras F5»; esa promesa sería incompatible con la revalidación segura de permisos y pertenencia. Sí se evita descargar otra vez los miles de eventos o hacer una consulta masiva a la BD.

## Migración y conservación

`2026_10_14_000002_publish_events_and_calendar_tasks.php` agrega estados, publicación, versión y outbox. Conserva eventos anteriores como publicados; antiguos eventos manuales `tarea` pasan a `actividad`, guardando `categoria_anterior`. No se borran ni se convierten en tareas del Aula. Deshabilita la política antigua de grupos irrestrictos.

`2026_10_14_000003_preserve_event_recipient_purge.php` permite eliminar el registro de entrega al purgar físicamente un usuario, sin añadir una nueva barrera a la política de retención existente. No purga usuarios ni elimina matrículas; las restricciones académicas siguen vigentes.

Para recursos históricos ya visibles, se usa la fecha disponible/creación como estimación explícita (`publicacion_estimada`); no existe evidencia para reconstruir la publicación exacta de borradores antiguos. Nuevas publicaciones guardan el instante real. No se generan notificaciones retrospectivas.

Se verificó migración PostgreSQL desde cero y desde copia restaurada. Aplicada a los cinco tenants locales, conservando usuarios, matrículas, convocatorias, credenciales institucionales y calificaciones según las firmas del ensayo. Respaldo previo al cambio principal: `C:/Users/Pedro/AppData/Local/Temp/colegio-eventos-20261014-164502-65bba8dc0fe5`; previo al ajuste de retención: `C:/Users/Pedro/AppData/Local/Temp/colegio-eventos-20261014-165309-499b4aa7a031`. Las bases temporales de prueba se eliminaron; los dumps se conservaron para recuperación.

## Evidencia reproducible

- `php artisan test --filter=AcademicModulesTest`: regresión académica/Aula/Eventos.
- `php artisan test --filter='event_workflow|event_calendar|event_mail'`: casos nuevos de 1.000 estudiantes, lote de 10, scope, borradores, deduplicación, reintentos, recuperación de estado, retención, zona horaria, transportes de correo y escape HTML.
- `php tests/Support/migrate-flexible-grading.php --verify --verify-events --pg-bin=...`: respaldos y ensayo PostgreSQL; `--apply` solo tras revisar el entorno local y los respaldos.
- Frontend: `npm run test:ui:events`, `npm test`, `npm run check:i18n`, `npm run build`. Pruebas de navegador con API en memoria, no contra buzones reales.

### Resultado de la verificación local

- Suite backend completa, ejecutada sin otras suites PHP simultáneas: **374 pruebas aprobadas, 3.510 aserciones**, 469,37 segundos.
- Casos específicos nuevos de eventos/calendario/correo: **13 pruebas aprobadas, 95 aserciones**. Incluyen 1.000 destinatarios sintéticos; el transporte se simula, no acredita llegada a buzones reales.
- Frontend: **53 pruebas aprobadas**, comprobación de 1.558 claves estáticas de traducción sin ausencias ES/EN, compilación y presupuesto de bundles aprobados.
- Recorrido `test:ui:events` aprobado en escritorio/móvil y ES/EN, incluida la vista de la plantilla real de correo con datos sintéticos. `git diff --check` sin errores en los tres repositorios.
- En una primera ejecución solapada con otras pruebas PHP falló una aserción de almacenamiento vacío en matrículas. El caso pasó aislado (62 aserciones) y la repetición completa sin solapamiento pasó. No se modificó matrículas para silenciarlo; evitar suites simultáneas que compartan `Storage::fake('tenant')`.
- Despachador y worker dedicado comprobados puntualmente con cola vacía; no se dejaron procesos permanentes instalados. Esto no sustituye una prueba de transporte real ni supervisión operativa.

Pendientes fuera de esta entrega: boletines, indicadores y observaciones; calendario recurrente/festivos y sincronizaciones; avisos separados de edición/cancelación y notificaciones de tareas por correo. Esta entrega conecta tareas con calendario, no activa correos de tareas.
