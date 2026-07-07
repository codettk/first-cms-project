<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Enums\MediaType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'media_type' => null, // MA 확정 전 NULL
            'status' => ContentStatus::Uploading,
            'created_by' => User::factory(),
        ];
    }

    public function registered(): static
    {
        return $this->state(['status' => ContentStatus::Registered]);
    }

    public function processing(MediaType $mediaType = MediaType::Video): static
    {
        return $this->state(['status' => ContentStatus::Processing, 'media_type' => $mediaType]);
    }

    public function ready(MediaType $mediaType = MediaType::Video): static
    {
        return $this->state([
            'status' => ContentStatus::Ready,
            'media_type' => $mediaType,
            'published_at' => now(),
        ]);
    }

    public function deleted(): static
    {
        return $this->state(['status' => ContentStatus::Deleted, 'deleted_at' => now()]);
    }
}
