<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookDiagnosticSnapshot extends Model
{
    protected $fillable = [
        'publish_target_id',
        'external_id',
        'collected_at',
        'status',
        'post_metadata',
        'insights_payload',
        'normalized_metrics',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'post_metadata' => 'array',
            'insights_payload' => 'array',
            'normalized_metrics' => 'array',
        ];
    }

    public function publishTarget(): BelongsTo
    {
        return $this->belongsTo(PublishTarget::class);
    }
}
