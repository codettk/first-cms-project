<?php

namespace Database\Factories;

use App\Models\Content;
use Illuminate\Database\Eloquent\Factories\Factory;

class MediaFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_id' => Content::factory(),
            'original_filename' => fake()->word().'.mov',
            'ext' => 'mov',
            'detected_mime' => null,
            'file_size' => fake()->numberBetween(1_000_000, 8_000_000_000),
            'checksum' => hash('sha256', fake()->uuid()),
            'temp_path' => 'temp/'.fake()->uuid().'.mov',
            'media_info' => null,
        ];
    }
}
