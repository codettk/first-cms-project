<?php

namespace Database\Factories;

use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('??????')).'_INGEST',
            'name' => fake()->words(2, true),
            'media_type' => MediaType::Video,
            'version' => 1,
            'is_active' => false, // 유형당 활성 1건 partial unique 충돌 방지 — 활성은 명시적으로
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }
}
