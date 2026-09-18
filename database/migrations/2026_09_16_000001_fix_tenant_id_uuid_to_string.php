<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CORRECCION: `tenant_id` se declaro como `uuid` en `impersonations`,
 * `platform_audit_logs` y `stored_files`, pero el id real del tenant
 * (`tenants.id`) es un string corto (p.ej. "v4dl3khc8z"), no un UUID RFC4122.
 * Postgres rechaza esos valores en una columna `uuid` (SQLSTATE 22P02), lo que
 * bloqueaba la suplantacion del superadmin. Se convierte la columna a
 * VARCHAR(255) conservando los datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE impersonations ALTER COLUMN tenant_id TYPE VARCHAR(255) USING tenant_id::text');
        DB::statement('ALTER TABLE platform_audit_logs ALTER COLUMN tenant_id TYPE VARCHAR(255) USING tenant_id::text');
        DB::statement('ALTER TABLE stored_files ALTER COLUMN tenant_id TYPE VARCHAR(255) USING tenant_id::text');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE impersonations ALTER COLUMN tenant_id TYPE UUID USING tenant_id::uuid');
        DB::statement('ALTER TABLE platform_audit_logs ALTER COLUMN tenant_id TYPE UUID USING tenant_id::uuid');
        DB::statement('ALTER TABLE stored_files ALTER COLUMN tenant_id TYPE UUID USING tenant_id::uuid');
    }
};
