<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublishTarget extends Model
{
    protected $fillable = [
        'post_id',
        'channel',
        'status',
        'external_id',
        'external_url',
        'error_message',
        'retry_count',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function publishAttempts()
    {
        return $this->hasMany(PublishAttempt::class);
    }

    public function publishEvents()
    {
        return $this->hasMany(PublishEvent::class);
    }

    public function facebookDiagnosticSnapshots()
    {
        return $this->hasMany(FacebookDiagnosticSnapshot::class);
    }
}
