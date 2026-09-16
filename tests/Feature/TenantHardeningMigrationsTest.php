<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantHardeningMigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_indices_nullable_impiden_duplicados_activos_y_permiten_reutilizar_soft_delete(): void
    {
        Schema::create('grupos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('grado_id');
            $table->unsignedBigInteger('ano_lectivo_id');
            $table->unsignedBigInteger('jornada_id')->nullable();
            $table->string('nombre');
            $table->softDeletes();
            $table->unique(['grado_id', 'ano_lectivo_id', 'jornada_id', 'nombre']);
        });
        Schema::create('espacios_fisicos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sede_id')->nullable();
            $table->string('nombre');
            $table->softDeletes();
            $table->unique(['sede_id', 'nombre']);
        });

        $migration = require database_path('migrations/tenant/2026_08_06_000003_fix_nullable_scope_unique_indexes.php');
        $migration->up();

        DB::table('grupos')->insert([
            'grado_id' => 1,
            'ano_lectivo_id' => 1,
            'jornada_id' => null,
            'nombre' => 'A',
            'deleted_at' => null,
        ]);
        $this->assertUniqueViolation(fn () => DB::table('grupos')->insert([
            'grado_id' => 1,
            'ano_lectivo_id' => 1,
            'jornada_id' => null,
            'nombre' => 'A',
            'deleted_at' => null,
        ]));
        DB::table('grupos')->where('nombre', 'A')->update(['deleted_at' => now()]);
        DB::table('grupos')->insert([
            'grado_id' => 1,
            'ano_lectivo_id' => 1,
            'jornada_id' => null,
            'nombre' => 'A',
            'deleted_at' => null,
        ]);

        DB::table('espacios_fisicos')->insert([
            'sede_id' => null,
            'nombre' => 'Biblioteca',
            'deleted_at' => null,
        ]);
        $this->assertUniqueViolation(fn () => DB::table('espacios_fisicos')->insert([
            'sede_id' => null,
            'nombre' => 'Biblioteca',
            'deleted_at' => null,
        ]));
        DB::table('espacios_fisicos')->where('nombre', 'Biblioteca')->update(['deleted_at' => now()]);
        DB::table('espacios_fisicos')->insert([
            'sede_id' => null,
            'nombre' => 'Biblioteca',
            'deleted_at' => null,
        ]);

        $this->assertSame(2, DB::table('grupos')->where('nombre', 'A')->count());
        $this->assertSame(2, DB::table('espacios_fisicos')->where('nombre', 'Biblioteca')->count());
    }

    public function test_migracion_elimina_contrasenas_temporales_legacy(): void
    {
        // RefreshDatabase migra el esquema central. Esta columna solo existe
        // en los esquemas tenant, por lo que el fixture la agrega de forma
        // explicita antes de simular un registro legacy.
        Schema::table('users', function (Blueprint $table): void {
            $table->text('temporary_password')->nullable();
        });

        $user = User::create([
            'name' => 'Usuario legacy',
            'email' => 'legacy-password@colegio.test',
            'password' => 'Admin123!',
            'role' => 'docente',
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => true,
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'temporary_password' => 'payload-cifrado-reversible',
        ]);

        $migration = require database_path('migrations/tenant/2026_08_06_000004_null_legacy_temporary_passwords.php');
        $migration->up();

        $this->assertNull(DB::table('users')->where('id', $user->id)->value('temporary_password'));
        $this->assertTrue($user->fresh()->must_change_password);
    }

    private function assertUniqueViolation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('El indice permitio un duplicado activo dentro del mismo alcance.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE constraint failed', $exception->getMessage());
        }
    }
}
