<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publish_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('correlation_id')->index()->nullable();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('publish_target_id')->nullable()->constrained('publish_targets')->onDelete('set null');
            $table->string('channel')->nullable();

            $table->string('level')->default('info'); // info, warning, error
            $table->string('event_type');
            $table->text('message');
            $table->json('context')->nullable();

            $table->timestamps();

            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_events');
    }
};
