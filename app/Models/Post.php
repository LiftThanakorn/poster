<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = [
        'title',
        'replacement_of_post_id',
        'slug',
        'wp_content',
        'fb_caption',
        'fb_comment',
        'fb_comment_2',
        'action_buttons',
        'wp_category_id',
        'status',
        'featured_image_id',
        'image_editor_data',
        'pdf_media_id',
        'author_id',
        'published_at',
        'scheduled_at',
        'drive_sync_enabled',
        'drive_employee_category',
        'drive_announcement_stage',
        'drive_document_date',
        'drive_position_title',
        'drive_custom_filename',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'action_buttons' => 'array',
        'image_editor_data' => 'array',
        'drive_sync_enabled' => 'boolean',
        'drive_document_date' => 'date:Y-m-d',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function targets()
    {
        return $this->hasMany(PublishTarget::class);
    }

    public function publishAttempts()
    {
        return $this->hasMany(PublishAttempt::class);
    }

    public function publishEvents()
    {
        return $this->hasMany(PublishEvent::class);
    }

    public function media()
    {
        return $this->hasMany(PostMedia::class);
    }

    public function pdfMedia()
    {
        return $this->belongsTo(PostMedia::class, 'pdf_media_id');
    }
}
