<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class QrCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'code',
        'target_url',
        'is_active',
        'expires_at',
        'scan_count',
        'styling',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'scan_count' => 'integer',
            'styling' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $qrCode): void {
            Cache::forget("qr_code:{$qrCode->code}");
            $originalCode = $qrCode->getOriginal('code');
            if ($originalCode && $originalCode !== $qrCode->code) {
                Cache::forget("qr_code:{$originalCode}");
            }
        });

        static::deleted(function (self $qrCode): void {
            Cache::forget("qr_code:{$qrCode->code}");
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(QrCodeScan::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRedirectable(): bool
    {
        return $this->is_active
            && ! $this->isExpired()
            && ! empty($this->target_url)
            && ! str_starts_with($this->target_url, '{');
    }

    /**
     * Resolve placeholder target URLs (e.g. {drive_url}, {wordpress_url}) on QR codes embedded in a post.
     *
     * @param  array<string, string>  $replacements
     */
    public static function resolvePostQrPlaceholders(Post $post, array $replacements): void
    {
        $elements = $post->image_editor_data['elements'] ?? [];
        if (! is_array($elements) || empty($replacements)) {
            return;
        }

        foreach ($elements as $el) {
            if (($el['type'] ?? '') === 'qr' && ! empty($el['qrCodeId'])) {
                /** @var self|null $qr */
                $qr = self::query()->find($el['qrCodeId']);
                if ($qr && isset($replacements[$qr->target_url]) && $replacements[$qr->target_url] !== '') {
                    $qr->update([
                        'target_url' => $replacements[$qr->target_url],
                    ]);
                }
            }
        }
    }

    public function getScanUrlAttribute(): string
    {
        $base = config('app.qr_public_url');
        if ($base) {
            return rtrim($base, '/').'/qr/'.$this->code;
        }

        return route('qr.redirect', ['code' => $this->code]);
    }

    public function getImageUrlAttribute(): string
    {
        return route('qr.image', ['code' => $this->code]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
