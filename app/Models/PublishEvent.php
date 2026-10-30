<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublishEvent extends Model
{
    protected $fillable = [
        'correlation_id',
        'post_id',
        'publish_target_id',
        'channel',
        'level',
        'event_type',
        'message',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
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
