<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaFolder extends Model
{
    protected $fillable = [
        'name',
        'color',
        'parent_id',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class, 'folder_id');
    }

    /**
     * @return array<int, array{id: int, name: string, color: string, parent_id: ?int}>
     */
    public function ancestors(): array
    {
        $ancestors = [];
        $current = $this->parent;
        while ($current) {
            array_unshift($ancestors, [
                'id' => $current->id,
                'name' => $current->name,
                'color' => $current->color,
                'parent_id' => $current->parent_id,
            ]);
            $current = $current->parent;
        }

        return $ancestors;
    }

    public function fullPath(): string
    {
        $names = array_column($this->ancestors(), 'name');
        $names[] = $this->name;

        return implode(' / ', $names);
    }
}
