<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publish_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('correlation_id')->index();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('publish_target_id')->constrained('publish_targets')->onDelete('cascade');
            $table->string('channel');
            $table->integer('attempt_number');
            $table->string('status'); // queued, processing, published, failed, retrying
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();

            $table->string('error_type')->nullable(); // auth_required, rate_limited, network_error, validation, etc.
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('http_status')->nullable();
            $table->boolean('retryable')->default(false);

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['post_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_attempts');
    }
};
