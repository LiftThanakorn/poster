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
        Schema::create('facebook_diagnostic_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publish_target_id')->constrained('publish_targets')->cascadeOnDelete();
            $table->string('external_id')->index();
            $table->timestamp('collected_at')->index();
            $table->string('status')->default('success'); // success, partial, failed
            $table->json('post_metadata')->nullable();
            $table->json('insights_payload')->nullable();
            $table->json('normalized_metrics')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['publish_target_id', 'collected_at'], 'fb_diag_target_collected_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_diagnostic_snapshots');
    }
};
