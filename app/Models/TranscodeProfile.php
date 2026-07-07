<?php

namespace App\Models;

use App\Enums\ProfileMediaType;
use App\Enums\RenditionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TranscodeProfile extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'media_type' => ProfileMediaType::class,
            'rendition_type' => RenditionType::class,
            'frame_rate' => 'decimal:3',
            'params' => 'array',
            'is_active' => 'bool',
        ];
    }

    public function renditions(): HasMany
    {
        return $this->hasMany(MediaRendition::class, 'profile_id');
    }
}
