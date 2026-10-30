<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('publish_targets', function (Blueprint $table) {
            $table->string('status', 32)->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('publish_targets')
            ->whereNotIn('status', ['pending', 'processing', 'published', 'failed'])
            ->update(['status' => 'pending']);

        Schema::table('publish_targets', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'published', 'failed'])
                ->default('pending')
                ->change();
        });
    }
};
