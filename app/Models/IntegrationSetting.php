<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    protected $fillable = [
        'channel',
        'settings',
        'encrypted_credentials',
        'status',
        'last_checked_at',
        'last_error',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'encrypted_credentials',
    ];

    protected $casts = [
        'settings' => 'array',
        'encrypted_credentials' => 'encrypted:array', // Store credentials encrypted
        'last_checked_at' => 'datetime',
    ];
}
