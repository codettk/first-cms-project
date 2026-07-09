<?php

namespace Database\Factories;

use App\Enums\RenditionType;
use App\Enums\StorageZone;
use App\Models\Content;
use App\Models\MediaFile;
use Illuminate\Database\Eloquent\Factories\Factory;

class MediaRenditionFactory extends Factory
{
    public function definition(): array
    {
        $content = Content::factory();

        return [
            'content_id' => $content,
            'media_file_id' => MediaFile::factory()->for($content, 'content'),
            'rendition_type' => RenditionType::ProxyVideo,
            'storage_zone' => StorageZone::Proxy,
            'variant_key' => '720p',
            'path' => 'proxy/2026/07/'.fake()->uuid().'_720p.mp4',
            'file_size' => fake()->numberBetween(100_000, 500_000_000),
            'checksum' => hash('sha256', fake()->uuid()),
            'mime_type' => 'video/mp4',
            'width' => 1280,
            'height' => 720,
            'duration_ms' => fake()->numberBetween(10_000, 3_600_000),
        ];
    }

    public function master(): static
    {
        return $this->state([
            'rendition_type' => RenditionType::Master,
            'storage_zone' => StorageZone::Master,
            'variant_key' => 'default',
            'path' => 'master/2026/07/'.fake()->uuid().'.mov',
            'mime_type' => 'video/quicktime',
        ]);
    }

    public function thumbnail(): static
    {
        return $this->state([
            'rendition_type' => RenditionType::Thumbnail,
            'storage_zone' => StorageZone::Thumbnail,
            'variant_key' => 'thumb_480',
            'path' => 'thumbnail/2026/07/'.fake()->uuid().'.webp',
            'mime_type' => 'image/webp',
            'width' => 480,
            'height' => 270,
            'duration_ms' => null,
        ]);
    }

    public function catalog(int $timecodeMs = 5000): static
    {
        return $this->state([
            'rendition_type' => RenditionType::Catalog,
            'storage_zone' => StorageZone::Catalog,
            'variant_key' => 'catalog_default',
            'path' => 'catalog/2026/07/'.fake()->uuid().'_'.$timecodeMs.'.webp',
            'mime_type' => 'image/webp',
            'width' => 320,
            'height' => 180,
            'duration_ms' => null,
            'timecode_ms' => $timecodeMs,
        ]);
    }
}
