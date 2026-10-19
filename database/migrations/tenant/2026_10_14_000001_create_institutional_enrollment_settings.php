<?php

declare(strict_types=1);

use App\Models\Ingreso\GradoInstitucional;
use App\Support\AcademicTokenIndex;
use App\Support\OpaqueUrlToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingreso_configuracion', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->unsignedInteger('version')->default(0);
            $table->text('privacidad')->nullable();
            $table->boolean('requiere_revision')->default(false);
            $table->timestamps();
        });
        Schema::create('ingreso_grados', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 64)->unique();
            $table->string('nombre', 120);
            $table->string('nivel', 80);
            $table->unsignedSmallInteger('edad_min')->nullable();
            $table->unsignedSmallInteger('edad_max')->nullable();
            $table->timestamps();
        });
        Schema::table('grados', function (Blueprint $table) {
            $table->foreignId('ingreso_grado_id')->nullable()->constrained('ingreso_grados')->restrictOnDelete();
        });
        Schema::table('ingreso_solicitudes', function (Blueprint $table) {
            $table->json('validacion_edad')->nullable();
            $table->json('consentimiento_privacidad')->nullable();
        });
        Schema::create('ingreso_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('ingreso_solicitudes')->restrictOnDelete();
            $table->uuid('envio_token')->nullable();
            $table->json('validacion_edad');
            $table->json('consentimiento_privacidad');
            $table->timestamp('enviada_en');
            $table->unique(['solicitud_id', 'envio_token']);
        });

        $annual = [];
        foreach (DB::table('grados')->join('niveles', 'niveles.id', '=', 'grados.nivel_id')
            ->orderBy('grados.id')->get(['grados.id', 'grados.nombre', 'niveles.nivel_educativo']) as $grade) {
            $key = GradoInstitucional::keyFor($grade->nivel_educativo, $grade->nombre);
            DB::table('ingreso_grados')->insertOrIgnore(['clave' => $key, 'nombre' => $grade->nombre,
                'nivel' => $grade->nivel_educativo, 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('ingreso_grados')->where('clave', $key)->value('id');
            DB::table('grados')->where('id', $grade->id)->update(['ingreso_grado_id' => $id]);
            if (tenancy()->initialized) {
                $annual[OpaqueUrlToken::for('grado', $grade->id)] = $id;
            }
        }

        // Preserve original campaign documents. Only unanimous old settings are suggested.
        $notices = [];
        $ranges = [];
        $campaigns = DB::table('ingreso_campanas')->get(['id', 'configuracion'])->keyBy('id');
        foreach ($campaigns as $campaign) {
            $config = json_decode($campaign->configuracion, true, flags: JSON_THROW_ON_ERROR);
            if (filled($config['privacidad'] ?? null)) {
                $notices[trim($config['privacidad'])] = true;
            }
            foreach ($config['grados'] as $grade) {
                if (isset($annual[$grade['token']])) {
                    $ranges[$annual[$grade['token']]][json_encode([$grade['edad_min'] ?? null, $grade['edad_max'] ?? null])] = true;
                }
            }
        }
        $conflict = count($notices) > 1;
        foreach ($ranges as $id => $values) {
            if (count($values) > 1) {
                $conflict = true;

                continue;
            }
            [$min, $max] = json_decode(array_key_first($values), true);
            DB::table('ingreso_grados')->where('id', $id)->update(['edad_min' => $min, 'edad_max' => $max]);
        }
        DB::table('ingreso_configuracion')->insert(['id' => 1, 'version' => 0,
            'privacidad' => count($notices) === 1 ? array_key_first($notices) : null,
            'requiere_revision' => $conflict, 'created_at' => now(), 'updated_at' => now()]);

        $timezone = tenancy()->initialized ? tenant()->zonaHorariaInstitucional() : 'America/Bogota';
        DB::table('ingreso_solicitudes')->whereNotNull('enviada_en')->orderBy('id')->chunkById(100, function ($applications) use ($campaigns, $timezone) {
            foreach ($applications as $application) {
                $data = json_decode($application->datos ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                $config = json_decode($campaigns[$application->campana_id]->configuracion, true, flags: JSON_THROW_ON_ERROR);
                if (empty($data['nacimiento'])) {
                    continue;
                }
                $gradeId = $application->grado_aprobado_id ?? $application->grado_id;
                $token = tenancy()->initialized ? OpaqueUrlToken::for('grado', $gradeId) : null;
                $rule = collect($config['grados'])->firstWhere('token', $token);
                $date = $rule['edad_corte'] ?? CarbonImmutable::parse($application->enviada_en, 'UTC')->setTimezone($timezone)->toDateString();
                $age = (int) CarbonImmutable::parse($data['nacimiento'], $timezone)->diffInYears(CarbonImmutable::parse($date, $timezone), false);
                DB::table('ingreso_solicitudes')->where('id', $application->id)->update([
                    'validacion_edad' => json_encode(['grado_id' => (int) $gradeId, 'nacimiento' => $data['nacimiento'], 'edad' => $age,
                        'fecha' => $date, 'edad_min' => $rule['edad_min'] ?? null, 'edad_max' => $rule['edad_max'] ?? null,
                        'configuracion_version' => 0], JSON_THROW_ON_ERROR),
                    'consentimiento_privacidad' => json_encode(['texto' => $config['privacidad'] ?? '',
                        'version' => hash('sha256', $config['privacidad'] ?? '')], JSON_THROW_ON_ERROR),
                ]);
                $snapshot = DB::table('ingreso_solicitudes')->where('id', $application->id)
                    ->first(['validacion_edad', 'consentimiento_privacidad']);
                DB::table('ingreso_envios')->insert(['solicitud_id' => $application->id,
                    'validacion_edad' => $snapshot->validacion_edad, 'consentimiento_privacidad' => $snapshot->consentimiento_privacidad,
                    'enviada_en' => $application->enviada_en]);
            }
        });
        if (tenancy()->initialized) {
            app(AcademicTokenIndex::class)->rebuild();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ingreso_envios');
        Schema::table('ingreso_solicitudes', fn (Blueprint $table) => $table->dropColumn(['validacion_edad', 'consentimiento_privacidad']));
        Schema::table('grados', fn (Blueprint $table) => $table->dropConstrainedForeignId('ingreso_grado_id'));
        DB::table(AcademicTokenIndex::TABLE)->where('resource', 'ingreso-grado')->delete();
        Schema::dropIfExists('ingreso_grados');
        Schema::dropIfExists('ingreso_configuracion');
    }
};
