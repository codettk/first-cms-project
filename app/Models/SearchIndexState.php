<?php

namespace App\Models;

use App\Enums\IndexStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchIndexState extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'content_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => IndexStatus::class,
            'indexed_at' => 'immutable_datetime',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
