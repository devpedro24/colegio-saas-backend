# Colegio SaaS — Backend

Backend de la **plataforma SaaS multi-tenant de gestión académica y de convivencia
para colegios de Colombia**. Cada colegio es un *tenant* con **base de datos
PostgreSQL exclusiva** (`RN-AI-001`) y acceso por subdominio `<slug>.<dominio>`.

- **Stack:** Laravel 12 · PHP 8.2 · PostgreSQL 16 · [`stancl/tenancy`](https://tenancyforlaravel.com) v3 (database-per-tenant).
- La **fuente de verdad del negocio** vive en `../documentacion-girgit/` (reglas `RN-XX-NNN`).

## Requisitos

- PHP >= 8.2 con extensiones `pdo_pgsql` y `pgsql`
- Composer 2.x
- PostgreSQL 16 corriendo en `127.0.0.1:5432`

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
