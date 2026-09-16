<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Los IDs actuales de tenant son cortos alfanumericos, no UUID. Estas
        // tres tablas centrales conservaban el tipo uuid de una version previa.
        $driver = DB::connection()->getDriverName();
        foreach (['impersonations', 'platform_audit_logs', 'stored_files'] as $tableName) {
            if ($driver === 'pgsql') {
                DB::statement("ALTER TABLE {$tableName} ALTER COLUMN tenant_id TYPE VARCHAR(255) USING tenant_id::text");
            } elseif ($driver !== 'sqlite') {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $column = $table->string('tenant_id', 255);
                    if ($tableName === 'platform_audit_logs') {
                        $column->nullable();
                    }
                    $column->change();
                });
            }
        }

        Schema::table('impersonations', function (Blueprint $table) {
            $table->uuid('session_id')->nullable()->after('id');
            $table->text('motivo')->nullable()->after('tenant_id');
            $table->string('ticket', 120)->nullable()->after('motivo');
        });

        DB::table('impersonations')->orderBy('id')->each(function (object $row): void {
            DB::table('impersonations')->where('id', $row->id)->update([
                'session_id' => (string) Str::uuid(),
            ]);
        });

        Schema::table('impersonations', function (Blueprint $table) {
            $table->uuid('session_id')->nullable(false)->change();
            $table->unique('session_id');
            $table->index(['superadmin_id', 'tenant_id', 'ended_at'], 'impersonations_active_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('impersonations', function (Blueprint $table) {
            $table->dropIndex('impersonations_active_lookup');
            $table->dropUnique(['session_id']);
            $table->dropColumn(['session_id', 'motivo', 'ticket']);
        });
    }
};
