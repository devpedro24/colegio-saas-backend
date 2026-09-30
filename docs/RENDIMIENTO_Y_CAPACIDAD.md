# Rendimiento y capacidad del Colegio SaaS

Este documento registra la optimización del 30 de septiembre de 2026 y la base operativa para un VPS de 4 vCPU y 16 GB RAM. Se han mejorado rutas concretas y añadido controles reproducibles; no se ha certificado la capacidad de 1.000 usuarios ni se ha desplegado en un VPS. Las plantillas de `deploy/` necesitan valores reales, prueba en Linux y medición en staging antes de usarse en producción.

## Cambios implementados

- Las peticiones GET simultáneas a la misma URL comparten una promesa solo dentro de la misma sesión y contexto. No se guarda información personal en almacenamiento del navegador. Las escrituras, cambios de sesión y eventos remotos descartan la reutilización de lecturas pendientes.
- F5 mantiene una comprobación `/me`, necesaria para validar sesión, permisos y estado actual. Esa respuesta incluye el onboarding del usuario del colegio. La interfaz lo reutiliza durante cinco minutos con invalidación por cambios; la suplantación consulta por separado el colegio activo para no mezclarlo con la identidad de plataforma.
- El primer enlace WebSocket ya no fuerza otra carga completa. Una reconexión sí recupera cambios perdidos. Los eventos remotos siguen actualizando permisos y pantallas. Las mutaciones envían `X-Socket-ID`, excluyendo el eco hacia el mismo socket; otras pestañas y usuarios sí reciben la notificación.
- Los eventos `ApplicationChanged` usan la cola configurada fuera del ciclo HTTP; con `sync` se conserva la ejecución inmediata local. En producción se debe supervisar la cola `realtime`. El almacenamiento de colas SQL se fija a la conexión central para no escribir jobs en una base de colegio.
- Solo el logo público versionado usa caché larga e inmutable, con ETag y respuesta 304. El hash se guarda al cargar el logo y se migra para los existentes. Un logo nuevo cambia la URL; JSON, sesión y archivos privados conservan `no-store`. Una recarga forzada puede revalidar: no se promete cero solicitudes de imagen en cualquier circunstancia.
- Los selectores opacos se resuelven mediante un índice persistente dentro de cada base de colegio y luego se reaplica la consulta autorizada. No cambian los tokens, no se publican IDs y no se cachean permisos. Las notas y selecciones múltiples se resuelven por lotes.
- Planillas reutilizan componentes y calificaciones ya cargados por página; no repiten consultas por alumno. Los boletines cargan componentes, actividades y notas por lote para el año/grupo, sin modificar las fórmulas SIEE ni convertir notas pendientes en cero.
- Las pantallas académicas se descargan por ruta. Se retiró del arranque el registro global de Chart.js, que no tenía consumidores en el código. El build ahora comprueba presupuestos gzip de 550.000 bytes para JS inicial y 230.000 para CSS inicial: subirlos requiere justificar la regresión.
- `http.performance` registra duración, cantidad de consultas y tiempo SQL agregado para solicitudes lentas y una muestra opcional. No registra SQL, parámetros, URL completa, cookies, cuerpos ni datos personales. No sustituye la auditoría, que permanece activa.

## Evidencia local y límites

En PostgreSQL local, 690 clases existentes de Inmaculada, 30 iteraciones después de calentamiento, buscando la última clase de un recorrido ordenado:

| Resolución del selector | Media | p95 |
|---|---:|---:|
| Recorrido Eloquent anterior | 13,611 ms | 18,130 ms |
| Índice y scope autorizado | 0,538 ms | 0,970 ms |

Es aproximadamente 25 veces menos tiempo para esta operación y este conjunto de datos, no para una página completa. Se comparan ambos algoritmos sobre la misma base y proceso; no hay concurrencia, carga de red ni simulación del VPS. Repetir con `php artisan rendimiento:selectores --tenant=inmaculada --iterations=30`; el comando es de solo lectura y no muestra datos de alumnos.

El navegador aislado confirmó un `/me` y cero `/onboarding/status` en el arranque y en F5 para un rector con bootstrap completo. La prueba de Reverb comprueba invalidaciones remotas, reconexión y permisos. El JS inicial compilado quedó en 501.435 bytes gzip y el CSS en 215.055 bytes; son tamaños de transferencia, no mediciones de LCP.

Las dos migraciones nuevas se aplicaron a los cinco tenants PostgreSQL locales existentes, sin reemplazar datos. Los futuros tenants reciben las mismas migraciones y el listener mantiene sus selectores. Las pruebas unitarias con SQLite complementan, pero no sustituyen, esta comprobación PostgreSQL.

Verificación de este corte: suite completa de 213 pruebas backend con 1.483 aserciones, más una prueba adicional de exclusión del socket/cola (5 aserciones) ejecutada después; 24 pruebas frontend, TypeScript/build, presupuesto de transferencia y tres recorridos de navegador (F5, Reverb y navegación/acciones académicas). El simulacro de carga HTTP k6 y la operación Linux no se han ejecutado.

## Corrección de consultas repetidas al navegar

La segunda revisión del 30 de septiembre añade caché académica compartida en memoria, invalidada por cambios locales/remotos, y elimina las cargas dobles de áreas/asignaciones/horarios. El respaldo comprueba datos visibles cada minuto si Reverb no está disponible y cada 15 minutos con conexión; la reconexión recupera de inmediato. El contrato HTTP privado conserva `no-store`, autenticación, CSRF y auditoría. F5 reconstruye la sesión y los datos necesarios; no se almacena JSON académico privado en disco.

El fallback HTTP de `/asignaciones` ahora publica exclusivamente `schedule`, no `all`; evita invalidar años y perfil por un cambio de asignación. La comprobación `RealtimeSyncTest` protege ese contrato. El detalle de solicitudes por pantalla y el comando `test:ui:cache` están en `colegio-saas-frontend/docs/ENTREGAS_2026-09.md`. El tiempo de `artisan serve` en Windows no basta para atribuir 500 ms al SQL o certificar capacidad: correlacionar con `http.performance` y medir HTTP en staging. No se ejecutaron migraciones ni se alteraron datos de colegios durante esta segunda revisión.

## Objetivos de aceptación propuestos

Antes de lanzar, aprobar estos objetivos con tráfico y datos representativos:

| Flujo | Objetivo de staging |
|---|---|
| Lecturas académicas paginadas | p95 menor de 500 ms y p99 menor de 1.500 ms |
| Fallos HTTP inesperados | Menos del 1 %; un 401, 403 o 429 invalida la interpretación de capacidad del flujo |
| Mutaciones habituales | p95 menor de 1 segundo, con auditoría y validaciones habilitadas |
| Tiempo real | Cambio visible en menos de 2 segundos en p95; cola sin crecimiento sostenido |
| Interacción web | LCP menor de 2,5 s, INP menor de 200 ms, CLS menor de 0,1, medidos en equipos/redes objetivo |
| Recursos del VPS | Sin OOM ni swap sostenido; CPU sin saturación sostenida; espacio libre superior al 20 % |

Son objetivos, no resultados ya alcanzados. Mil usuarios conectados no equivalen a mil solicitudes por segundo. Mil personas realizando una consulta cada diez segundos producen aproximadamente 100 solicitudes/segundo; una pantalla con varias consultas y las escrituras alteran esa cuenta. Debe medirse también con varios colegios, diferentes roles, planillas grandes, cierres de período, cargas de archivos y sesiones WebSocket.

## Base de producción para el VPS

Hostinger publica KVM 4 con 4 vCPU, 16 GB RAM, 200 GB NVMe y **16 TB de transferencia**, no 16 GB. Puede ser un punto de partida; la capacidad de CPU compartida, el volumen académico y el comportamiento real deciden si alcanza. Las especificaciones comerciales no garantizan latencia ni concurrencia de esta aplicación.

La topología propuesta es Nginx con TLS, frontend compilado, PHP-FPM, PostgreSQL, Redis, dos workers separados, scheduler, Reverb y ClamAV. Puede empaquetarse en Docker manteniendo los mismos procesos y límites; no se ha creado ni validado un compose final porque aún faltan dominio, política de certificados, volúmenes y respaldo externos. No ejecutar Vite, `artisan serve` ni `queue:listen` como servidores de producción. No se introduce Octane sin pruebas específicas de aislamiento de estado entre tenants.

Punto inicial para medir, no valores universales:

- PHP-FPM: 16 hijos como máximo, 4 iniciales y reciclado cada 500 solicitudes. El límite de 256 MB por proceso permite hasta unos 4 GB; medir RSS y ajustar. Más procesos no crean CPU y pueden empeorar la cola de espera.
- PostgreSQL: considerar `shared_buffers=2GB`, `effective_cache_size=8GB`, `work_mem=8MB`, `maintenance_work_mem=256MB`, `max_connections=100`. `effective_cache_size` es una estimación, no una reserva. `work_mem` puede multiplicarse por operadores y procesos; medir antes de aumentar. Activar estadísticas de consultas en staging sin exportar datos sensibles. No desactivar WAL, fsync ni restricciones.
- Redis de caché: instancia privada separada, límite inicial 256 MB, `allkeys-lru`. Redis de colas/sesiones: inicialmente 512 MB, `noeviction`, persistencia AOF y alerta de memoria/errores. No aplicar una política de expulsión a jobs ni publicar puertos Redis.
- Workers: uno `realtime` y uno `default`, supervisados; `timeout=60` menor que `retry_after=90`. Alertar sobre jobs fallidos y antigüedad de cola. Ver `worker@.service.example`. El scheduler ejecuta `php artisan schedule:run` cada minuto.
- Reverb: proceso independiente supervisado, interfaz privada, WSS mediante Nginx. Para aproximarse a mil sockets, probar `ext-uv`, límites de archivos abiertos de 65.535 y conexiones del proxy; `stream_select` tiene límites que no se resuelven solamente aumentando RAM.
- ClamAV requiere memoria propia y actualizaciones de firmas. Mantener margen para el sistema, caché de disco, respaldos y picos; no asignar los 16 GB completos a PostgreSQL/PHP. Si el escáner falla, las cargas deben seguir fallando cerradas.
- Disco: 200 GB incluye sistema, datos, WAL, archivos, logs y respaldos temporales. Los planes del código permiten cuotas mayores que ese disco; no equivale a espacio físicamente disponible. Usar almacenamiento externo o ampliar antes de vender/consumir esa capacidad. Rotar logs y mantener respaldos cifrados fuera del VPS, con restauración ensayada. No eliminar auditoría para mejorar rendimiento.

Las plantillas son para Linux con servicios locales. Si se usan contenedores, ajustar nombres de servicios y rutas internas, conservar `Host`, mantener privado FPM/Redis/PostgreSQL/ClamAV y montar volúmenes persistentes. El único acceso público debe ser HTTPS/WSS. No confiar ciegamente en encabezados de proxy externos; el ejemplo Nginx comunica HTTPS directamente a FPM. Un balanceador TLS distinto requiere una lista explícita de proxies confiables y pruebas de origen/CSRF.

## Despliegue y migraciones

1. Ensayar respaldo y restauración de la central, todas las bases tenant y archivos. Preparar release y secretos fuera de Git. El usuario PostgreSQL de la aplicación no debe ser superusuario; el provisionamiento actual necesita permiso de crear bases, cuya separación operacional debe decidirse antes de abrir producción.
2. Instalar dependencias bloqueadas (`composer install --no-dev --optimize-autoloader` y `npm ci`), ejecutar pruebas en CI y compilar el frontend. Configurar Vite con `VITE_REVERB_HOST=colegio.example`, puerto 443 y esquema `https`; solo la clave pública, nunca `REVERB_APP_SECRET`. Las variables del publicador backend permanecen privadas en `production.env.example`.
3. Durante una ventana controlada, ejecutar `php artisan migrate --force` y `php artisan tenants:migrate --force`; verificar todos los tenants. No activar el nuevo código mientras falte la tabla de selectores. Las migraciones `000005_version_school_branding` y `000006_index_academic_public_tokens` son aditivas y conservan los selectores existentes.
4. Tras importar datos por SQL o cambiar deliberadamente `APP_KEY`, ejecutar `php artisan academico:indexar-selectores` para todos, o `--tenant=slug` para uno. Una rotación también afecta cookies y otros campos cifrados: no usar `key:generate` como rutina de despliegue. El importador local de Inmaculada ya reconstruye el índice al finalizar, pero no debe ejecutarse para desplegar.
5. Con el entorno final cargado, ejecutar `php artisan optimize`, validar `nginx -t` y la configuración FPM de la versión instalada; comprobar que `storage` y `bootstrap/cache` son escribibles por el proceso, sin permisos abiertos globales.
6. Reiniciar FPM (OPcache no revisa timestamps en este perfil), reiniciar workers con `queue:restart` y reiniciar Reverb de forma supervisada. Los assets con hash son inmutables; `index.html` revalida. No cachear JSON ni aplicar caché global del proxy a `/api`.
7. Verificar `/up`, login/MFA, cookies Secure, CSRF, una consulta y mutación por colegio, auditoría, imágenes, subida escaneada, colas, reconexión y provisionamiento de un colegio vacío. Guardar reporte de carga y métricas del host. No borrar volúmenes al recrear contenedores.

## Pruebas reproducibles y carga

Backend: `php vendor/phpunit/phpunit/phpunit`. Frontend: `npm.cmd test`, `npm.cmd run build`, `npm.cmd run test:ui:performance` y `npm.cmd run test:ui:realtime` (esta última requiere Reverb local). La prueba UI usa datos aislados y no modifica la base real.

`tests/load/academic-read.js` es un escenario k6 de lecturas autenticadas y pausas de 5 a 15 segundos. Usa **una sesión distinta de staging por usuario virtual**, no un token compartido ni una tormenta de logins. Preparar `sessions.local.json` a partir del ejemplo, con sesiones temporales y selectores reales de colegios de prueba. Está ignorado por Git; proteger el archivo, no adjuntarlo a reportes y revocar las sesiones al terminar. Cada ruta debe estar autorizada para esa cuenta. No desactivar MFA, CSRF, auditoría ni límites para conseguir un resultado favorable.

Ejemplo desde `tests/load`, con k6 instalado en un generador externo al VPS:

```sh
k6 run -e ALLOW_LOAD_TEST=1 -e VUS=100 -e SESSION_FILE=./sessions.local.json academic-read.js
```

Repetir con 200 y, solo después de revisar CPU, RAM, I/O, conexiones y colas, con 1.000. Cada ejecución sube durante 2 minutos, mantiene 5 y baja durante 1. Es un smoke de lecturas, no una certificación: añadir sostenimiento de al menos 30 minutos, escenarios de escrituras con reversión controlada en staging, ráfagas, reconexión masiva de sockets y carga de archivos escaneados. Una API que devuelve vacío incorrectamente puede ser rápida; contrastar contenidos con fixtures, no solo códigos HTTP.

No se ejecutó esta carga k6 ni se validaron Nginx/FPM en Linux en este equipo: no están instalados k6, Docker ni el VPS. No probar mil usuarios sobre el servidor PHP de desarrollo y extrapolarlo a producción. Los logs `http.performance` permiten localizar solicitudes lentas; una muestra mezclada con todos los casos lentos no representa por sí sola un p95 global.

## Fuentes técnicas

- [Hostinger y límites de sus planes](https://www.hostinger.com/support/6976044-parameters-and-limits-of-hosting-plans-in-hostinger/): especificaciones comerciales del VPS, no capacidad de esta aplicación.
- [Despliegue Laravel 12](https://laravel.com/docs/12.x/deployment): optimización, servidor web y caché de configuración.
- [Laravel Reverb en producción](https://laravel.com/docs/12.x/reverb): servicios, proxy, archivos abiertos y bucle de eventos.
- [Escenario ramping VUs de k6](https://grafana.com/docs/k6/latest/using-k6/scenarios/executors/ramping-vus/): ejecución escalonada de usuarios virtuales.
