<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrCodeScan extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'qr_code_id',
        'scanned_at',
        'ip_hash',
        'user_agent',
        'device_type',
        'platform',
        'browser',
        'referrer',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
        ];
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }
}
