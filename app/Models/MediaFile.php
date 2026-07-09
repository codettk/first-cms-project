<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaFile extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'media_info' => 'array',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function renditions(): HasMany
    {
        return $this->hasMany(MediaRendition::class);
    }
}
