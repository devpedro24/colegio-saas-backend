<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\Aula;
use App\Models\Academico\Grupo;
use Illuminate\Support\Facades\DB;

/** An Aula belongs to a curriculum subject and a group, not to a teacher assignment. */
final class AulaProvisioningService
{
    public function syncGroup(Grupo $group): int
    {
        if ($group->trashed()) return 0;

        $subjectIds = DB::table('materias_curriculares')
            ->where('ano_lectivo_id', $group->ano_lectivo_id)
            ->where('grado_id', $group->grado_id)
            ->pluck('materia_id');
        $created = 0;
        foreach ($subjectIds as $subjectId) {
            $aula = Aula::query()->firstOrCreate(
                ['grupo_id' => $group->id, 'materia_id' => $subjectId],
                ['ano_lectivo_id' => $group->ano_lectivo_id],
            );
            if ($aula->wasRecentlyCreated) $created++;
        }

        return $created;
    }

    public function syncGrade(int $yearId, int $gradeId): int
    {
        $created = 0;
        Grupo::query()->where('ano_lectivo_id', $yearId)->where('grado_id', $gradeId)
            ->chunkById(100, function ($groups) use (&$created): void {
                foreach ($groups as $group) $created += $this->syncGroup($group);
            });

        return $created;
    }

    public function syncYear(int $yearId): int
    {
        $created = 0;
        Grupo::query()->where('ano_lectivo_id', $yearId)
            ->chunkById(100, function ($groups) use (&$created): void {
                foreach ($groups as $group) $created += $this->syncGroup($group);
            });

        return $created;
    }

    public function syncAll(): int
    {
        $created = 0;
        DB::table('anos_lectivos')->orderBy('id')->pluck('id')->each(function ($yearId) use (&$created): void {
            $created += $this->syncYear((int) $yearId);
        });

        return $created;
    }
}
