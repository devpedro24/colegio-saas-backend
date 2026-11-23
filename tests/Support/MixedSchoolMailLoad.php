<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Jobs\SendAulaMailBatch;
use App\Jobs\SendEventMailBatch;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Aula;
use App\Models\Academico\AulaSeccion;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Support\OpaqueUrlToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

trait MixedSchoolMailLoad
{
    public function test_concurrent_aula_and_event_workers_deliver_one_thousand_bulk_notices_once(): void
    {
        config(['cache.default' => 'database', 'mail.default' => 'load-test',
            'mail.mailers.load-test' => ['transport' => 'smtp']]);
        $f = $this->gradebookFixture();
        $section = $f['school']->run(function () {
            $assignment = AsignacionDocente::sole();
            $aula = Aula::create($assignment->only('ano_lectivo_id', 'materia_id', 'grupo_id'));
            $section = AulaSeccion::create(['aula_id' => $aula->id, 'periodo_id' => Periodo::sole()->id,
                'titulo' => 'Biología', 'orden' => 1, 'visible_estudiantes' => true, 'autor_id' => $assignment->docente_id]);
            $student = User::where('role', 'estudiante')->sole();
            $role = $student->roles()->sole();
            DB::transaction(function () use ($student, $role, $aula) {
                for ($i = 1; $i < 500; $i++) {
                    $id = DB::table('users')->insertGetId(['email' => "alumno-$i@example.test", 'name' => "Estudiante $i",
                        'password' => $student->getRawOriginal('password'), 'role' => 'estudiante', 'status' => 'active']);
                    DB::table('model_has_roles')->insert(['model_id' => $id, 'model_type' => $student->getMorphClass(), 'role_id' => $role->id]);
                    DB::table('matriculas')->insert(['estudiante_id' => $id, 'grupo_id' => $aula->grupo_id,
                        'ano_lectivo_id' => $aula->ano_lectivo_id, 'estado' => 'activa']);
                }
            });

            return OpaqueUrlToken::for('aula-seccion', $section->id);
        });
        $this->gradebookAuthenticate($f, 'docente');
        $this->postJson($f['base'].'/aula/secciones/'.$section.'/recursos', $this->input(true, [
            'titulo' => 'Guía de biología', 'configuracion' => ['enviar_correo' => true],
            'contenido' => ['html' => '<p>Guía para el grupo.</p>'],
        ]))->assertCreated();
        $event = $this->postJson($f['base'].'/eventos?opaque=1', [
            'titulo' => 'Feria escolar', 'descripcion' => 'Encuentro del grupo', 'categoria' => 'actividad',
            'fecha' => '2028-02-15', 'hora_inicio' => '09:00', 'hora_fin' => '10:00', 'institucional' => false,
            'grupo_tokens' => [$f['groupToken']], 'materia_token' => $f['subjectToken'], 'enviar_correo' => true,
        ])->assertCreated()->json('data');
        $this->postJson($f['base'].'/eventos/'.$event['url_token'].'/publicar?opaque=1', ['version' => $event['version']])->assertOk();
        $expected = $f['school']->run(function () {
            $this->assertSame(500, DB::table('user_notification_updates')->where('correo_estado', 'pendiente')->count());
            $this->assertSame(500, DB::table('evento_correos')->where('estado', 'pendiente')->count());

            return User::where('role', 'estudiante')->pluck('email')->sort()->values()->all();
        });

        $db = DB::connection('pgsql');
        $db->statement('CREATE TABLE mail_load_receipts (recipient text, subject text, accepted bigint, job bigint, worker int)');
        $db->statement('CREATE TABLE mail_load_workers (worker int primary key, ready boolean default false, scheduled bigint default 0, finished bigint default 0)');
        $control = $db->table('mail_load_workers');
        $control->insert([['worker' => 1], ['worker' => 2]]);
        $workers = [];
        $start = now()->timestamp;
        $receipts = $db->table('mail_load_receipts');
        $jobs = $db->table('jobs')->where('queue', 'event-mail');
        try {
            foreach ([1, 2] as $worker) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/mixed-mail-worker.php'), (string) $worker],
                    base_path(), ['CACHE_STORE' => 'database', 'SCHOOL_MAIL_PER_MINUTE' => '10',
                        'SCHOOL_MAIL_PER_DAY' => '400', 'EVENT_MAIL_BATCH_SIZE' => '10', 'EVENT_MAIL_BATCH_INTERVAL' => '60']);
                $process->setTimeout(300)->start();
                $workers[] = $process;
            }
            $this->waitForMailWorkers($workers, fn () => (clone $control)->where('ready', true)->count() === 2);
            // Run the scheduler through each active minute; check again just before the daily window opens.
            foreach ([0, 1, 2] as $day) {
                foreach ([...range(0, 40), 1439] as $minute) {
                    $tick = $start + ($day * 1440 + $minute) * 60;
                    Carbon::setTestNow(Carbon::createFromTimestampUTC($tick));
                    foreach (['aula:publicar-novedades', 'eventos:enviar-correos'] as $command) {
                        $this->artisan($command, ['--tenant' => $f['school']->slug])->assertExitCode(0);
                    }
                    if ($day === 0 && $minute === 0) {
                        $this->assertSame(2, $jobs->count());
                    }
                    if ($jobs->exists()) {
                        $control->update(['scheduled' => $tick]);
                        $this->waitForMailWorkers($workers, fn () => (clone $control)->where('finished', $tick)->count() === 2);
                    }
                    $this->assertSame(0, $jobs->count());
                }
                $this->assertSame(min(1000, 360 * ($day + 1)), $receipts->count());
            }

            // Repeat both jobs after completion. Accepted mail must not be emitted again.
            $f['school']->run(function () use ($f) {
                SendAulaMailBatch::dispatch((string) $f['school']->getKey());
                SendEventMailBatch::dispatch((string) $f['school']->getKey());
            });
            $tick = now()->addMinute()->timestamp;
            $control->update(['scheduled' => $tick]);
            $this->waitForMailWorkers($workers, fn () => (clone $control)->where('finished', $tick)->count() === 2);
        } finally {
            $control->update(['scheduled' => -1]);
            foreach ($workers as $worker) {
                $worker->stop(1);
            }
        }

        $sent = $receipts->orderBy('accepted')->get();
        $this->assertCount(1000, $sent);
        foreach (['Nuevo recurso en Aula', 'Nuevo evento del colegio: Feria escolar'] as $subject) {
            $this->assertSame($expected, $sent->where('subject', $subject)->pluck('recipient')->sort()->values()->all());
        }
        $this->assertCount(2, $sent->pluck('worker')->unique());
        $this->assertLessThanOrEqual(10, $sent->countBy('job')->max());
        foreach ([9 => 60, 360 => 86400] as $limit => $seconds) {
            for ($i = $limit; $i < $sent->count(); $i++) {
                $this->assertGreaterThanOrEqual($seconds, $sent[$i]->accepted - $sent[$i - $limit]->accepted);
            }
        }
        $f['school']->run(function () {
            $this->assertSame(500, DB::table('user_notification_updates')->where('correo_estado', 'enviado')->where('correo_intentos', 1)->count());
            $this->assertSame(500, DB::table('evento_correos')->where('estado', 'enviado')->where('intentos', 1)->count());
        });
        $this->assertSame(0, $jobs->count());
        $this->assertSame(0, $db->table('failed_jobs')->count());
    }

    private function waitForMailWorkers(array $workers, callable $ready): void
    {
        $deadline = microtime(true) + 45;
        while (! $ready()) {
            foreach ($workers as $worker) {
                if (! $worker->isRunning()) {
                    $this->fail('Mail worker stopped: '.$worker->getErrorOutput().$worker->getOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                $db = DB::connection('pgsql');
                $this->fail('Mail workers timed out: '.json_encode([
                    'workers' => $db->table('mail_load_workers')->get(),
                    'jobs' => $db->table('jobs')->where('queue', 'event-mail')->get(['id', 'reserved_at', 'attempts']),
                    'receipts' => $db->table('mail_load_receipts')->count(),
                ]));
            }
            usleep(20000);
        }
    }
}
