<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Academico\BoletinEmision;
use App\Models\Tenant;
use App\Services\BulletinArchiveSync;
use Illuminate\Console\Command;

final class OrganizeBulletinFiles extends Command
{
    protected $signature = 'storage:organize-bulletins {--tenant= : Slug del colegio} {--apply : Respaldar, verificar y actualizar rutas}';

    protected $description = 'Organiza PDF privados de boletines por año, grado, grupo, período y revisión';

    public function handle(): int
    {
        $count = 0;
        $query = Tenant::query()->when($this->option('tenant'), fn ($q) => $q->where('slug', $this->option('tenant')));
        foreach ($query->cursor() as $tenant) {
            $tenant->run(function () use ($tenant, &$count): void {
                foreach (BoletinEmision::whereNotNull('pdf_path')->orderBy('id')->cursor() as $issue) {
                    if (! app(BulletinArchiveSync::class)->relocate($issue, (bool) $this->option('apply'))) {
                        continue;
                    }
                    $count++;
                    $this->line($tenant->slug.': revisión '.$issue->revision.' preparada para organizar.');
                }
            });
        }
        $this->info($count.' archivos '.($this->option('apply') ? 'organizados y verificados.' : 'por organizar (sin aplicar).'));

        return self::SUCCESS;
    }
}
