<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('qr_code_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qr_code_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scanned_at')->useCurrent();
            $table->string('ip_hash', 64);
            $table->text('user_agent')->nullable();
            $table->string('device_type', 32)->default('other')->index();
            $table->string('platform', 64)->default('Other');
            $table->string('browser', 64)->default('Other');
            $table->text('referrer')->nullable();

            $table->index(['qr_code_id', 'scanned_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_code_scans');
    }
};
