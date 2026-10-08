<?php

use App\Rbac\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingreso_campanas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ano_lectivo_id')->constrained('anos_lectivos')->restrictOnDelete();
            $t->string('nombre', 120);
            $t->boolean('abierta')->default(false);
            $t->date('desde');
            $t->date('hasta');
            $t->json('configuracion');
            $t->timestamps();
        });
        Schema::create('ingreso_solicitudes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campana_id')->constrained('ingreso_campanas')->restrictOnDelete();
            $t->foreignId('grado_id')->constrained('grados')->restrictOnDelete();
            $t->foreignId('grado_aprobado_id')->nullable()->constrained('grados')->restrictOnDelete();
            $t->foreignId('estudiante_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('matricula_id')->nullable()->constrained('matriculas')->restrictOnDelete();
            $t->string('email');
            $t->string('estado', 30)->default('borrador')->index();
            $t->string('pin_hash');
            $t->timestamp('pin_expira');
            $t->string('sesion_version', 64);
            $t->timestamp('email_verificado')->nullable();
            $t->timestamp('enviada_en')->nullable();
            $t->timestamp('consentimiento_en')->nullable();
            $t->json('datos')->nullable();
            $t->text('observacion')->nullable();
            $t->timestamps();
            $t->unique(['campana_id', 'email']);
        });
        Schema::create('ingreso_documentos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('solicitud_id')->constrained('ingreso_solicitudes')->restrictOnDelete();
            $t->string('requisito', 50);
            $t->unsignedInteger('version');
            // stored_files belongs to the central DB: ownership is checked on every read.
            $t->string('archivo_id');
            $t->string('nombre');
            $t->string('estado', 20)->default('pendiente');
            $t->text('observacion')->nullable();
            $t->foreignId('revisor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['solicitud_id', 'requisito', 'version']);
        });
        Schema::create('ingreso_historial', function (Blueprint $t) {
            $t->id();
            $t->foreignId('solicitud_id')->constrained('ingreso_solicitudes')->restrictOnDelete();
            $t->string('evento', 50);
            $t->text('observacion')->nullable();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('ingreso_perfiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('estudiante_id')->unique()->constrained('users')->restrictOnDelete();
            $t->string('tipo_documento', 20);
            $t->string('numero_documento', 40);
            $t->json('datos');
            $t->timestamps();
            $t->unique(['tipo_documento', 'numero_documento']);
        });
        Schema::create('ingreso_notificaciones', function (Blueprint $t) {
            $t->id();
            $t->string('clave')->unique();
            $t->string('email');
            $t->string('asunto');
            $t->text('contenido')->nullable();
            $t->timestamp('enviada_en')->nullable();
            $t->timestamps();
        });
        Schema::table('users', fn (Blueprint $t) => $t->timestamp('temporary_password_expires_at')->nullable());
        foreach (PermissionMatrix::permissions() as $entry) {
            if (! str_starts_with($entry['key'], 'ingreso.')) {
                continue;
            }
            $permission = Permission::findOrCreate($entry['key'], 'web');
            foreach ($entry['cells'] as $role => $cell) {
                if (PermissionMatrix::classifyCell($cell)['default']) {
                    Role::where('name', $role)->first()?->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('temporary_password_expires_at'));
        foreach (['ingreso_notificaciones', 'ingreso_perfiles', 'ingreso_historial', 'ingreso_documentos', 'ingreso_solicitudes', 'ingreso_campanas'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
