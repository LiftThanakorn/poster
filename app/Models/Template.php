<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    protected $fillable = [
        'name',
        'wp_template',
        'fb_template',
        'fb_comment',
        'fb_comment_2',
        'action_buttons',
        'featured_image_id',
        'pdf_media_id',
        'image_editor_data',
        'drive_sync_enabled',
        'drive_employee_category',
        'drive_announcement_stage',
    ];

    protected $casts = [
        'image_editor_data' => 'array',
        'action_buttons' => 'array',
        'drive_sync_enabled' => 'boolean',
    ];

    public function featuredImage()
    {
        return $this->belongsTo(PostMedia::class, 'featured_image_id');
    }

    public function pdfMedia()
    {
        return $this->belongsTo(PostMedia::class, 'pdf_media_id');
    }
}
