<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublishAttempt extends Model
{
    protected $fillable = [
        'correlation_id',
        'post_id',
        'publish_target_id',
        'channel',
        'attempt_number',
        'status',
        'queued_at',
        'started_at',
        'finished_at',
        'duration_ms',
        'error_type',
        'error_code',
        'error_message',
        'http_status',
        'retryable',
        'metadata',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'retryable' => 'boolean',
        'metadata' => 'array',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function publishTarget()
    {
        return $this->belongsTo(PublishTarget::class);
    }
}
