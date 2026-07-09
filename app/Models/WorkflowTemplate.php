<?php

namespace App\Models;

use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowTemplate extends Model
{
    use HasFactory;

    public const null UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'media_type' => MediaType::class,
            'is_active' => 'bool',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowTemplateStep::class, 'template_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'template_id');
    }
}
