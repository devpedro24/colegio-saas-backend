<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\WorkDraftFileController;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneWorkDrafts extends Command
{
    protected $signature = 'drafts:prune';

    protected $description = 'Elimina borradores privados vencidos de plataforma y colegios.';

    public function handle(): int
    {
        $prune = function () {
            if (Schema::hasTable('work_drafts')) {
                DB::table('work_drafts')->where('expires_at', '<=', now())->delete();
            }
            if (tenancy()->initialized && Schema::hasTable('work_draft_files')) {
                WorkDraftFileController::prune();
            }
        };
        $prune();
        foreach (Tenant::cursor() as $tenant) {
            $tenant->run($prune);
        }

        return self::SUCCESS;
    }
}
