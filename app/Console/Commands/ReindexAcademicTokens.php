<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\AcademicTokenIndex;
use Illuminate\Console\Command;

class ReindexAcademicTokens extends Command
{
    protected $signature = 'academico:indexar-selectores {--tenant= : Slug del colegio; omitir para todos}';

    protected $description = 'Reconstruye el índice de selectores públicos después de importaciones SQL o rotación de APP_KEY.';

    public function handle(): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))->get();
        if ($tenants->isEmpty()) {
            $this->error('No se encontró ningún colegio.');

            return self::FAILURE;
        }
        foreach ($tenants as $tenant) {
            $count = $tenant->run(fn () => app(AcademicTokenIndex::class)->rebuild());
            $this->info($tenant->slug.': '.$count.' selectores indexados.');
        }

        return self::SUCCESS;
    }
}
