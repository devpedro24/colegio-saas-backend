# Colegio SaaS — Backend

Backend de la **plataforma SaaS multi-tenant de gestión académica y de convivencia
para colegios de Colombia**. Cada colegio es un *tenant* con **base de datos
PostgreSQL exclusiva** (`RN-AI-001`) y acceso por subdominio `<slug>.<dominio>`.

- **Stack:** Laravel 12 · PHP 8.2 · PostgreSQL 16 · [`stancl/tenancy`](https://tenancyforlaravel.com) v3 (database-per-tenant).
- La **fuente de verdad del negocio** vive en `../documentacion-girgit/` (reglas `RN-XX-NNN`).

## Requisitos

- PHP >= 8.2 con extensiones `pdo_pgsql` y `pgsql`
- Composer 2.x
- Node.js 20+ y npm (para Vite)
- PostgreSQL 16 corriendo en `127.0.0.1:5432`

## Puesta en marcha

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# Edita .env y pon tu DB_USERNAME / DB_PASSWORD de PostgreSQL
```

`CENTRAL_DOMAINS` recibe una lista CSV de hosts sin esquema ni puerto. En
producción define también `TENANT_BASE_DOMAIN` con el sufijo donde se publican
los colegios; ese host debe formar parte de `CENTRAL_DOMAINS`. Por ejemplo:

```dotenv
CENTRAL_DOMAINS=app.colegios.example.com
TENANT_BASE_DOMAIN=app.colegios.example.com
FRONTEND_URL=https://app.colegios.example.com
```

Así, el panel usa `app.colegios.example.com` y el tenant `san-jose` usa
`san-jose.app.colegios.example.com`.

Crea la base de datos central (una sola vez) y migra:

```bash
# En psql / pgAdmin:  CREATE DATABASE colegio_saas_central;
php artisan migrate --seed
php artisan superadmin:create admin@ejemplo.com
```

En desarrollo, la API, la cola, el log y Vite pueden iniciarse juntos con
`composer run dev`. Reverb y el scheduler deben mantenerse en procesos
separados:

```bash
composer run dev
php artisan reverb:start
php artisan schedule:work
```

Si prefieres procesos independientes, usa:

```bash
php artisan serve
php artisan queue:work --tries=3
php artisan reverb:start
php artisan schedule:work
npm run dev
```

## Provisionar un colegio (CU-001)

```bash
php artisan tenant:create "Colegio San Jose" colegio-san-jose rector@sanjose.edu.co --plan=estandar
```

Esto crea la BD aislada del colegio, corre sus migraciones, siembra su RBAC y
crea el usuario **rector** con una contraseña temporal que debe cambiar en el
primer ingreso (`RN-AU-360`).

Los tenants nuevos usan un identificador inmutable de 10 caracteres
alfanuméricos en minúscula, no un UUID. El nombre físico de la base de datos es
legible y conserva ese sufijo:

- Colegio: `tenant_<nombre-colegio>_<id-corto>`, por ejemplo
  `tenant_colegio_san_jose_k7x2m9p4qr`.
- Sede hija: `tenant_<nombre-colegio>_<nombre-sede>_<id-corto>`, por ejemplo
  `tenant_colegio_san_jose_sede_norte_a1b2c3d4e5`.

Los tenants legacy conservan su UUID como clave primaria; solo el sufijo del
nombre de BD se deriva a 10 caracteres. Todos los nombres se recortan sin
perder el sufijo para respetar el máximo de 63 bytes de PostgreSQL.

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
| `app/Models/Tenant.php` | Modelo Colegio (id corto; UUID solo legacy + slug + plan + estados) |
| `app/Tenancy/TenantDatabaseName.php` | Convención y límite del nombre físico de cada BD |
| `app/Console/Commands/CreateTenant.php` | Comando de provisioning `tenant:create` |
| `database/migrations/` | Migraciones **centrales** (tenants, domains, superadmins) |
| `database/migrations/tenant/` | Migraciones de **cada colegio** (usuarios, etc.) |
| `routes/web.php` | Rutas centrales (por dominio) |
| `routes/tenant.php` | Rutas del colegio (por subdominio) |

## Consistencia RBAC

El catálogo de roles, permisos y matriz vive en la base central. Cada mutación
se audita y se propaga sincrónicamente a todos los tenants. Si una propagación
falla, la transacción central se revierte y se ejecuta una sincronización de
compensación contra el catálogo restaurado; la API devuelve el resultado por
tenant para permitir reintento operativo. La política para roles o permisos
obsoletos es `preserve_assignments`: no se borran automáticamente si podrían
romper asignaciones existentes.

## Migraciones y verificación PostgreSQL

Antes de desplegar las migraciones de índices parciales, corrige posibles
duplicados activos en grupos o espacios físicos: PostgreSQL rechazará el nuevo
índice en lugar de descartar datos silenciosamente. Detén el tráfico y los
workers durante esta actualización; luego ejecuta, en este orden:

```bash
php artisan migrate --force
php artisan tenants:migrate --force
# Convierte secretos TOTP legacy antes de que el cast encrypted intente leerlos.
php artisan totp:encrypt-secrets
# Propaga el catalogo/matriz vigente (incluido archivos.gestionar) a cada tenant.
php artisan rbac:sync
php artisan test
```

`totp:encrypt-secrets` no tiene modo `--dry-run`, pero es idempotente: omite los
payloads que ya puede descifrar. Para instalaciones con MFA anterior debe
ejecutarse una vez después de migrar y antes de volver a abrir tráfico, de modo
que ningún proceso intente leer un secreto legacy con el cast cifrado.

La suite automatizada local usa SQLite y comprueba el contrato funcional, pero
no sustituye una prueba de humo en PostgreSQL para el DDL de índices parciales,
el provisioning físico de bases y su compensación. Esa prueba debe ejecutarse
en un entorno PostgreSQL de staging antes del despliegue.

## Ramas

- `main` — rama estable.
- `pedro-dev` — desarrollo de Pedro.
- `sebas-dev` — desarrollo de Sebas.
