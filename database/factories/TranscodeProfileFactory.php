<?php

namespace Database\Factories;

use App\Enums\ProfileMediaType;
use App\Enums\RenditionType;
use Illuminate\Database\Eloquent\Factories\Factory;

class TranscodeProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'TEST_PROFILE_'.strtoupper(fake()->unique()->lexify('?????')),
            'name' => fake()->words(2, true),
            'media_type' => ProfileMediaType::Video,
            'rendition_type' => RenditionType::ProxyVideo,
            'variant_key' => 'default',
            'output_format' => 'mp4',
            'codec' => 'h264',
            'width' => 1280,
            'height' => 720,
            'bitrate' => 2500,
            'audio_bitrate' => 128,
            'quality' => 23,
            'params' => ['preset' => 'medium'],
            'is_active' => true,
        ];
    }
}
