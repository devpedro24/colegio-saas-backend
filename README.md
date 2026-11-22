# Colegio SaaS — Backend

Backend de la **plataforma SaaS multi-tenant de gestión académica y de convivencia
para colegios de Colombia**. Cada colegio es un *tenant* con **base de datos
PostgreSQL exclusiva** (`RN-AI-001`) y acceso por subdominio `<slug>.<dominio>`.

- **Stack:** Laravel 12 · PHP 8.2 · PostgreSQL 16 · [`stancl/tenancy`](https://tenancyforlaravel.com) v3 (database-per-tenant).
- La **fuente de verdad del negocio** vive en `../documentacion-girgit/` (reglas `RN-XX-NNN`).

Entregas y límites del corte actual: [docs/ENTREGAS_2026-09.md](docs/ENTREGAS_2026-09.md) y [docs/SEGURIDAD_API.md](docs/SEGURIDAD_API.md).

Ingreso estudiantil: [configuración, permisos, migraciones y operación de correo](docs/INGRESO_ESTUDIANTIL.md). Matrícula directa por enlace, sin pagos ni cuentas de acudiente.

## Imagen del inicio de sesión

Plataforma: **Configuración > Imagen del inicio de sesión** (`/configuracion/imagen-acceso`). El superadministrador elige su acceso o busca un colegio/sede para ayudar a su rector. Colegio: **Ajustes institucionales > Imagen del inicio de sesión** (`/ajustes-institucionales/acceso`), solo rector con `academico.configurar` o sesión válida de soporte.

PNG/JPG/WebP, máximo 5 MB y 200 × 100 a 6000 × 6000 píxeles. La imagen personalizada cubre todo el fondo del panel derecho; puede recortarse visualmente según la proporción de la pantalla, sin alterar el archivo original. Los títulos y textos se muestran encima con una capa de contraste. Antes de guardar, **Previsualizar login** muestra el diseño compartido del acceso en escritorio y móvil, con el archivo seleccionado localmente y sin guardarlo ni iniciar sesión. Sin personalización se conserva el fondo azul con la ilustración base. Las imágenes del colegio pasan por `StorageService` (MIME, escáner y cuota); la imagen de plataforma usa disco privado y escáner. Guardar o restaurar exige la versión leída y deja auditoría; una carga rechazada conserva la imagen anterior. La imagen de acceso es pública y debe elegirse para ese fin; ningún otro archivo se publica.

El login consulta `GET /api/branding/login` en su propio dominio. La respuesta solo contiene `image_url`, `version` y `customized`; el recurso de imagen comprueba la versión y el colegio. La gestión usa `/api/platform/login-appearance`, `/api/colegios/{slug}/login-appearance` o `/api/login-appearance` según el contexto, con cookies HttpOnly y CSRF. El cambio se comunica por el tema `login-appearance` a los paneles autorizados.

Antes de activar en otro entorno, respaldar la base central y aplicar únicamente `php artisan migrate --path=database/migrations/2026_11_20_000001_create_login_appearances.php --force`. No requiere migración tenant ni reemplaza imágenes existentes durante la instalación. En producción se requiere el escáner operativo. La lectura conserva la ilustración predeterminada si no existe una personalización o no puede cargarla.

Pruebas: `php scripts/test-login-appearance-postgres.php` crea bases desechables con prefijo propio, ejecuta los recorridos de plataforma/rector, aislamiento, archivos, cuota, sesiones, concurrencia y restauración de la imagen predeterminada, y elimina esas bases. No migra ni modifica la base de la aplicación.

## Recuperación por enlace y OTP

Desde «Olvidé mi contraseña» se elige enlace (30 minutos, un uso) o código por correo (6 dígitos, 10 minutos, 5 intentos). Endpoints públicos con límites por IP: `POST /api/forgot-password`, `POST /api/reset-password`, `POST /api/forgot-password/otp` y `POST /api/reset-password/otp`. El OTP exige `email`, `challenge` opaco de 24 caracteres, `code` de 6 dígitos y contraseña confirmada. Nunca devuelve el código ni inicia una sesión. El cambio revoca sesiones, invalida recuperaciones pendientes y envía aviso sin incluir la nueva contraseña.

El almacén compartido `PASSWORD_RESET_OTP_STORE=database` utiliza las tablas de caché centrales existentes; no requiere migraciones nuevas. Mantener un backend persistente compartido entre procesos y nodos, con bloqueos. Vaciarlo invalida los códigos pendientes. La espera de 60 segundos y el máximo de 5 solicitudes/hora por cuenta/contexto son comunes a enlace y OTP. Un reenvío reemplaza el código anterior.

Ambos métodos usan `SchoolMailFactory`: correo institucional cuando está conectado o correo general. `MAIL_MAILER=log` solo registra el correo privado en desarrollo; configurar y verificar SMTP antes de afirmar entrega real. No imprimir códigos ni secretos al depurar en producción. Las respuestas de solicitud son genéricas incluso si falla el envío; el registro operativo deja una advertencia sin datos del proveedor.

Pruebas: `php vendor/bin/phpunit tests/Feature/PasswordRecoveryTest.php tests/Feature/SecurityFlowsTest.php`; aislamiento real entre colegios con `php scripts/test-password-recovery-postgres.php` (bases desechables, sin tocar la base operativa).

## Centro de soporte

`support_tickets` y `support_messages` viven en central (migración `2026_11_22_000001_create_support_tickets`). Respaldar central y aplicar `php artisan migrate --path=database/migrations/2026_11_22_000001_create_support_tickets.php --force` antes de servir el módulo; no hay migración tenant. La aplicación local conserva respaldo verificado antes de instalarla.

Rector: `GET/POST /api/support/tickets`, `GET /api/support/tickets/{token}`, `POST .../{token}/messages` y `PUT .../{token}/state`. Plataforma: mismas lecturas y acciones bajo `/api/platform/support/tickets`, sin crear casos en nombre del colegio. Todas requieren sesión, comprobación de rol y contexto; las mutaciones de navegador conservan CSRF y control de origen. No aceptan claves internas, ni las exponen en los presentadores. El ámbito del rector es su colegio principal y sus sedes, nunca otro colegio.

Las altas y mensajes usan `request_key` aleatorio hexadecimal de 24 caracteres para reintentos idempotentes. El estado exige `version` y rechaza cambios concurrentes con 409. Se bloquean filas en central para serializar altas y mensajes; las creaciones tienen límite de 5/minuto y 20/hora, mensajes 30/minuto y estados 20/minuto, separados por actor y contexto. Los casos activos se ordenan primero y después por prioridad del contrato y actividad. Listas de 20 casos y conversación paginada de 30 mensajes.

El tema Reverb `support` invalida solo la bandeja autorizada. Nunca contiene mensajes ni datos del solicitante. Crear un ticket no concede consentimiento ni inicia una sesión de suplantación. No se incluyen adjuntos ni envíos de correo por respuestas en este alcance.

Verificación: `php vendor/bin/phpunit tests/Feature/SupportTicketTest.php` y `php scripts/test-support-postgres.php` (bases desechables para probar rector, otro colegio, docente y equipo de plataforma).

## Cuenta, logo y suscripción

Cuenta y suscripción: `GET/POST /api/account/photo` administra únicamente la foto privada de la propia cuenta. Aplicar la migración central `2026_11_21_000001_create_account_photos` antes de servir el nuevo presentador de cuenta. Reutiliza validación, escáner y cuota; no edita fotos ajenas ni identidad estudiantil. Las cuentas compartidas entre sedes conservan la foto del colegio principal.

`GET /api/school-branding` y `GET/POST /api/school-branding/logo` operan en el colegio autorizado para rector con configuración o soporte de plataforma. Conservan lectura de logos anteriores y registran las nuevas cargas en el almacenamiento gestionado. `GET /api/subscription` presenta el contrato efectivo del colegio principal al rector o soporte autorizado, sin IDs internos; es consulta, no ejecuta cobros y los cambios comerciales permanecen en Plataforma.

## Actualización en tiempo real

Reverb comunica todos los cambios de la API mediante `application.changed` en
canales privados `tenant.<uuid>` y `platform`. El mensaje contiene únicamente las
secciones modificadas; el navegador consulta los datos con sus permisos actuales.

- `RealtimeServiceProvider` observa los modelos de la aplicación y los eventos de dominio.
- `SynchronizeRealtimeChanges` agrupa los avisos por petición y cubre escrituras masivas y relaciones. Las transacciones se notifican después del commit.
- Los cambios del catálogo de roles o planes sincronizan los permisos de los colegios mediante la cola, conservando las elecciones configurables del rector.
- Las lecturas y su auditoría no emiten avisos, para evitar ciclos de recarga.
- Para nuevos módulos, añadir su tema a `RealtimeChanges::resourceForPath` y al mapa `src/lib/realtime.ts` del frontend. Los temas desconocidos refrescan todo su contexto.

Mantener activos `php artisan reverb:start`, `php artisan queue:listen` y
`php artisan schedule:work`, además del servidor HTTP. `../start-all.bat` los inicia.
No se necesita una migración para esta integración.

Pruebas: `php artisan test --filter=Realtime` y, con Reverb activo, ejecutar
`npm run test:ui:realtime` desde el frontend. La prueba del navegador usa datos
simulados y un canal privado aislado; no modifica colegios reales.

## Requisitos del entorno

- PHP >= 8.2 con extensiones `pdo_pgsql` y `pgsql`
- Composer 2.x
- PostgreSQL 16 corriendo en `127.0.0.1:5432`

### Vista previa privada de documentos del Aula

DOC, DOCX, XLS, XLSX, PPT y PPTX se conservan en su formato original. La vista integrada
usa **ONLYOFFICE Docs autoalojado** en modo solo lectura; Laravel no convierte
estos archivos a PDF. La carga del archivo es independiente del estado del
visor: si ONLYOFFICE no está disponible, el archivo sigue adjunto y descargable.

En el VPS instala [ONLYOFFICE Docs Community](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-docker.aspx)
en un servicio propio, accesible por HTTPS desde el navegador. Configura el
servidor con `JWT_ENABLED=true` y un `JWT_SECRET` persistente y largo. En la API:

```dotenv
AULA_OFFICE_URL=https://office.tu-dominio.com
AULA_OFFICE_JWT_SECRET=el-mismo-JWT_SECRET-del-servidor
AULA_OFFICE_BACKEND_URL=https://api.tu-dominio.com
APP_URL=https://api.tu-dominio.com
```

`AULA_OFFICE_BACKEND_URL` debe poder alcanzarse desde ONLYOFFICE: se usa para
descargar el DOCX/PPTX original mediante una URL firmada de una hora y para el
callback de solo lectura. Puede diferir de `APP_URL` (por ejemplo en Docker
local). El navegador debe poder acceder a `AULA_OFFICE_URL`. Incluye el host de
la API en `TENANCY_CENTRAL_DOMAINS` cuando sea distinto del habitual.
No publiques el directorio `storage/app/tenants` ni desactives JWT. Reinicia PHP
y ejecuta `php artisan config:clear` si usas configuración cacheada. Comprueba
el servicio y la descarga firmada desde la red del contenedor antes de probar
la vista en el aula.

Para desarrollo en Windows con Docker Desktop (WSL 2), en `.env` del backend:

```dotenv
AULA_OFFICE_URL=http://localhost:8088
AULA_OFFICE_BACKEND_URL=http://host.docker.internal:8000
AULA_OFFICE_JWT_SECRET=<secreto aleatorio persistente de 32 bytes o más>
TENANCY_CENTRAL_DOMAINS=localhost,127.0.0.1,host.docker.internal
```

Inicia la API escuchando en `0.0.0.0:8000` para que el contenedor pueda acceder
a ella y ejecuta desde este repositorio:

```powershell
docker compose --env-file .env -f compose.office.local.yml up -d
```

El visor queda enlazado únicamente al loopback del equipo (`localhost:8088`).
El contenedor recibe solo el secreto JWT requerido por Compose, no las demás
credenciales del backend. Las instalaciones nativas de ONLYOFFICE Docs en
Windows [requieren Windows Server](https://helpcenter.onlyoffice.com/docs/installation/docs-community-install-windows.aspx).

Los adjuntos nuevos se guardan en
`storage/app/tenants/<colegio>_<id>/aula/Año_lectivo_2026/Grado_Primero_01A/Ciencias_Sociales/Cuarto_período/Segundo_preinforme/Nombre_de_la_sección/Nombre_del_recurso/archivo.docx`.
No se crean carpetas técnicas `periodos`, `recursos` o `materiales`. Los espacios
se cambian por `_`; ante nombres de archivo iguales y contenido diferente, el
segundo recibe `-2`, sin sobrescribir el primero. Títulos de secciones o
recursos idénticos en el mismo lugar reciben `_2` para no mezclar sus archivos.
Una nueva carga del mismo documento en dos recursos se guarda en ambas carpetas.
Para reorganizar adjuntos anteriores, revisa primero `php artisan storage:organize-aula`
y luego ejecuta `php artisan storage:organize-aula --tenant=<id> --apply`. La
orden verifica SHA-256 antes de actualizar cada ruta y deja una copia recuperable
del nombre anterior en `storage/app/aula-storage-migration-backups/`. Materiales
antiguos sin vínculo activo con un recurso quedan en `Archivos_sin_vinculo`;
no se reasignan automáticamente a un aula distinta.

### Política y apariencia del Aula

El Aula se aprovisiona automáticamente al existir un grupo y una materia en su
currículo; las asignaciones docentes no crean ni duplican contenido. El plan debe
incluir `aula` (por defecto Estándar y Premium). La personalización visual es
una capacidad separada, `aula_colores`, ubicada después de Aula en el catálogo
comercial y también incluida por defecto desde Estándar. Sin ella se usan los
colores predeterminados; los valores previamente guardados se conservan.

Solo el rector puede usar `GET/PUT /api/aula/configuracion`: se comprueban el
rol y `aula.configurar`; cambiar colores exige además
`aula.apariencia.configurar` y `aula_colores`. El color de cada posición de
período se reutiliza entre años lectivos, y un color independiente identifica
los preinformes en el menú lateral y el contenido principal. El texto mantiene
un color oscuro para preservar contraste. La misma configuración permite o
bloquea preparar contenido informativo en períodos cerrados; las calificaciones,
entregas y nuevas vinculaciones a Planilla siguen protegidas en cualquier caso.

En cada despliegue ejecuta `php artisan migrate --force` y
`php artisan tenants:migrate --force`; comprueba luego plan, permiso y visor con
un rector y un usuario sin permiso. Las migraciones son aditivas y no borran
los colores o materiales al retirar una capacidad comercial.

## Puesta en marcha

```bash
composer install
cp .env.example .env
php artisan key:generate
# Edita .env y pon tu DB_USERNAME / DB_PASSWORD de PostgreSQL
```

Crea la base de datos central (una sola vez) y migra:

```bash
# En psql / pgAdmin:  CREATE DATABASE colegio_saas_central;
php artisan migrate
php artisan db:seed --force # Primera instalación: planes y catálogo central de roles/permisos
```

Levanta el servidor:

```bash
php artisan serve
```

## Provisionar un colegio (CU-001)

```bash
php artisan tenant:create "Colegio San Jose" colegio-san-jose rector@sanjose.edu.co --plan=estandar
```

Esto crea la BD aislada del colegio (`tenant<uuid>`), corre sus migraciones y
crea el usuario **rector** con una contraseña temporal (debe cambiarla en el
primer ingreso, `RN-AU-360`).

### Migraciones en nuevos tenants y despliegues

- Al crear un colegio o una sede, el evento `TenantCreated` crea su base y ejecuta **todas** las migraciones de `database/migrations/tenant/` antes de sembrar roles o usuarios. Se verifica que no falte ninguna migración ni las columnas académicas anuales. El tenant permanece en `provisioning` y sin dominio publicado hasta terminar; si falla, no queda anunciado como configurado u operativo.
- Al desplegar cambios sobre tenants que **ya existen**, primero realiza respaldo y prueba en PostgreSQL de staging; después ejecuta `php artisan migrate --force` para la base central y `php artisan tenants:migrate --force` para todas las bases de tenant. Si cambia el catálogo RBAC central, actualízalo con `php artisan db:seed --class=RbacCatalogSeeder --force` y sincroniza los tenants con `php artisan rbac:sync`. Crear tenants nuevos no sustituye este paso para los existentes.
- La migración anual protege históricos: si encuentra varios años con datos académicos que antes compartían catálogos, se detiene antes de modificar esos catálogos. Ese caso necesita remapeo histórico específico antes del despliegue; no se deben borrar ni recrear datos para forzarla.
- Verifica el alta de un colegio y una sede nuevos en PostgreSQL de staging, además de un tenant existente migrado, antes de producción. Las pruebas automatizadas con SQLite no reemplazan esa verificación.

### Probar el aislamiento

Con el servidor arriba (`http://127.0.0.1:8000`):

| Contexto | Cómo |
|---|---|
| Central (plataforma) | `Host: localhost` → lista de colegios |
| Colegio | `Host: colegio-san-jose.localhost` → datos de **su** BD |

```bash
curl -H "Host: colegio-san-jose.localhost" http://127.0.0.1:8000
```

## Estructura relevante

| Ruta | Qué es |
|---|---|
| `app/Models/Tenant.php` | Modelo Colegio (UUID + slug + plan + estados) |
| `app/Console/Commands/CreateTenant.php` | Comando de provisioning `tenant:create` |
| `database/migrations/` | Migraciones **centrales** (tenants, domains, superadmins) |
| `database/migrations/tenant/` | Migraciones de **cada colegio** (usuarios, etc.) |
| `routes/web.php` | Rutas centrales (por dominio) |
| `routes/tenant.php` | Rutas del colegio (por subdominio) |

## Ramas

- `main` — rama estable.
- `pedro-dev` — desarrollo de Pedro.
- `sebas-dev` — desarrollo de Sebas.
