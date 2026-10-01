<?php

use Database\Seeders\RbacSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (tenancy()->initialized) {
            (new RbacSeeder)->run();
        }
    }

    public function down(): void {}
};
