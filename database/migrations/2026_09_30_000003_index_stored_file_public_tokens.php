<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stored_files', function (Blueprint $table) {
            $table->char('public_token', 24)->nullable()->unique();
        });

        // Existing signed URLs used this exact HMAC. Persist it so old links remain
        // resolvable until their short signature expiry without scanning all files.
        DB::table('stored_files')->select(['id', 'tenant_id'])->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $message = json_encode([(string) $row->tenant_id, 'stored-file', (string) $row->id],
                        JSON_THROW_ON_ERROR);
                    $digest = hash_hmac('sha256', $message, (string) config('app.key'), true);
                    $token = substr(rtrim(strtr(base64_encode($digest), '+/', '-_'), '='), 0, 24);
                    DB::table('stored_files')->where('id', $row->id)->update(['public_token' => $token]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('stored_files', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
