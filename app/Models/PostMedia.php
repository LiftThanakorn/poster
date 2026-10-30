<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PostMedia extends Model
{
    protected $fillable = [
        'post_id',
        'folder_id',
        'category',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'is_featured',
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
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'drive_document_date' => 'date:Y-m-d',
        'drive_synced_at' => 'datetime',
    ];

    protected $appends = [
        'url',
    ];

    protected static function booted(): void
    {
        static::saved(function (PostMedia $media): void {
            if (! $media->file_path) {
                return;
            }

            $appPath = storage_path('app/public/'.$media->file_path);
            $pubPath = public_path('storage/'.$media->file_path);

            if (file_exists($appPath) && ! file_exists($pubPath)) {
                @mkdir(dirname($pubPath), 0755, true);
                @copy($appPath, $pubPath);
            } elseif (file_exists($pubPath) && ! file_exists($appPath)) {
                @mkdir(dirname($appPath), 0755, true);
                @copy($pubPath, $appPath);
            }
        });

        static::deleted(function (PostMedia $media): void {
            if (! $media->file_path) {
                return;
            }

            $appPath = storage_path('app/public/'.$media->file_path);
            $pubPath = public_path('storage/'.$media->file_path);

            if (file_exists($appPath)) {
                @unlink($appPath);
            }
            if (file_exists($pubPath)) {
                @unlink($pubPath);
            }
        });
    }

    public function getUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return Storage::disk('public')->url($this->file_path);
    }

    public function folder()
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function featuredInPosts()
    {
        return $this->hasMany(Post::class, 'featured_image_id');
    }

    public function featuredInTemplates()
    {
        return $this->hasMany(Template::class, 'featured_image_id');
    }
}
