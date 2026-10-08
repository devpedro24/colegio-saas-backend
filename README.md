# Colegio SaaS — Backend

Backend de la **plataforma SaaS multi-tenant de gestión académica y de convivencia
para colegios de Colombia**. Cada colegio es un *tenant* con **base de datos
PostgreSQL exclusiva** (`RN-AI-001`) y acceso por subdominio `<slug>.<dominio>`.

- **Stack:** Laravel 12 · PHP 8.2 · PostgreSQL 16 · [`stancl/tenancy`](https://tenancyforlaravel.com) v3 (database-per-tenant).
- La **fuente de verdad del negocio** vive en `../documentacion-girgit/` (reglas `RN-XX-NNN`).

Entregas y límites del corte actual: [docs/ENTREGAS_2026-09.md](docs/ENTREGAS_2026-09.md) y [docs/SEGURIDAD_API.md](docs/SEGURIDAD_API.md).

Ingreso estudiantil: [configuración, permisos, migraciones y operación de correo](docs/INGRESO_ESTUDIANTIL.md). Matrícula directa por enlace, sin pagos ni cuentas de acudiente.

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
