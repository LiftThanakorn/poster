<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->foreignId('featured_image_id')
                ->nullable()
                ->after('fb_template')
                ->constrained('post_media')
                ->nullOnDelete();
            $table->json('image_editor_data')
                ->nullable()
                ->after('featured_image_id');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('image_editor_data');
            $table->dropConstrainedForeignId('featured_image_id');
        });
    }
};
