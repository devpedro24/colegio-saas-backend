<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('account_photos', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('version', 32)->default('default');
            $table->unsignedBigInteger('stored_file_id')->nullable();
            $table->string('disk')->nullable();
            $table->text('path')->nullable();
            $table->string('mime', 64)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('account_photos'); }
};
