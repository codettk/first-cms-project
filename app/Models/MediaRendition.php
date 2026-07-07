<?php

namespace App\Models;

use App\Enums\RenditionType;
use App\Enums\StorageZone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaRendition extends Model
{
    use HasFactory;

    public const null UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rendition_type' => RenditionType::class,
            'storage_zone' => StorageZone::class,
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TranscodeProfile::class, 'profile_id');
    }
}
