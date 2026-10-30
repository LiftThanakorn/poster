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
        Schema::table('posts', function (Blueprint $table) {
            $table->text('fb_comment_2')->nullable()->after('fb_comment');
            $table->json('action_buttons')->nullable()->after('fb_comment_2');
            $table->unsignedBigInteger('pdf_media_id')->nullable()->after('featured_image_id');
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->text('fb_comment_2')->nullable()->after('fb_template');
            $table->json('action_buttons')->nullable()->after('fb_comment_2');
            $table->unsignedBigInteger('pdf_media_id')->nullable()->after('featured_image_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['fb_comment_2', 'action_buttons', 'pdf_media_id']);
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn(['fb_comment_2', 'action_buttons', 'pdf_media_id']);
        });
    }
};
