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
        Schema::table('post_media', function (Blueprint $table) {
            $table->string('drive_file_id', 128)->nullable()->after('is_featured');
            $table->string('drive_file_name', 255)->nullable()->after('drive_file_id');
            $table->string('drive_web_view_link', 2048)->nullable()->after('drive_file_name');
            $table->string('drive_folder_id', 128)->nullable()->after('drive_web_view_link');
            $table->string('drive_folder_path', 512)->nullable()->after('drive_folder_id');
            $table->string('drive_employee_category', 120)->nullable()->after('drive_folder_path');
            $table->string('drive_announcement_stage', 120)->nullable()->after('drive_employee_category');
            $table->date('drive_document_date')->nullable()->after('drive_announcement_stage');
            $table->string('drive_position_title', 255)->nullable()->after('drive_document_date');
            $table->string('drive_sync_status', 32)->default('none')->after('drive_position_title');
            $table->string('drive_permission_status', 32)->nullable()->after('drive_sync_status');
            $table->timestamp('drive_synced_at')->nullable()->after('drive_permission_status');
            $table->text('drive_error_message')->nullable()->after('drive_synced_at');

            $table->index('drive_file_id', 'pm_drive_file_idx');
            $table->index(['drive_employee_category', 'drive_announcement_stage', 'drive_document_date'], 'pm_drive_filter_idx');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('drive_sync_enabled')->default(false)->after('wp_category_id');
            $table->string('drive_employee_category', 120)->nullable()->after('drive_sync_enabled');
            $table->string('drive_announcement_stage', 120)->nullable()->after('drive_employee_category');
            $table->date('drive_document_date')->nullable()->after('drive_announcement_stage');
            $table->string('drive_position_title', 255)->nullable()->after('drive_document_date');
            $table->string('drive_custom_filename', 255)->nullable()->after('drive_position_title');
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->text('fb_comment')->nullable()->after('fb_template');
            $table->boolean('drive_sync_enabled')->default(false)->after('image_editor_data');
            $table->string('drive_employee_category', 120)->nullable()->after('drive_sync_enabled');
            $table->string('drive_announcement_stage', 120)->nullable()->after('drive_employee_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn([
                'fb_comment',
                'drive_sync_enabled',
                'drive_employee_category',
                'drive_announcement_stage',
            ]);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn([
                'drive_sync_enabled',
                'drive_employee_category',
                'drive_announcement_stage',
                'drive_document_date',
                'drive_position_title',
                'drive_custom_filename',
            ]);
        });

        Schema::table('post_media', function (Blueprint $table) {
            $table->dropIndex('pm_drive_file_idx');
            $table->dropIndex('pm_drive_filter_idx');
            $table->dropColumn([
                'drive_file_id',
                'drive_file_name',
                'drive_web_view_link',
                'drive_folder_id',
                'drive_folder_path',
                'drive_employee_category',
                'drive_announcement_stage',
                'drive_document_date',
                'drive_position_title',
                'drive_sync_status',
                'drive_permission_status',
                'drive_synced_at',
                'drive_error_message',
            ]);
        });
    }
};
