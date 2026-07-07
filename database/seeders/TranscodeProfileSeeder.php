<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TranscodeProfileSeeder extends Seeder
{
    /**
     * transcode_profiles seed 12종 — Transcode Profile Spec §14.
     * v1은 VIDEO_PROXY_720P만 활성(다중 화질·HLS는 is_active=false로 예비 정의).
     * COMMON은 여러 유형에서 재사용되는 공통 프리셋(THUMBNAIL_DEFAULT·CATALOG_DEFAULT) 전용.
     */
    public function run(): void
    {
        $profiles = [
            [
                'code' => 'VIDEO_PROXY_720P', 'name' => '영상 프록시 720p', 'media_type' => 'VIDEO',
                'rendition_type' => 'PROXY_VIDEO', 'variant_key' => '720p',
                'output_format' => 'mp4', 'codec' => 'h264',
                'width' => 1280, 'height' => 720, 'bitrate' => 2500, 'audio_bitrate' => 128,
                'frame_rate' => null, 'quality' => 23, // CRF
                'params' => ['preset' => 'medium', 'pix_fmt' => 'yuv420p', 'faststart' => true,
                    'maxrate' => '2500k', 'bufsize' => '5000k', 'fps_cap' => 30,
                    'gpu_codec' => 'h264_nvenc', 'gpu_cq' => 26],
                'is_active' => true,
            ],
            [
                'code' => 'VIDEO_PROXY_480P', 'name' => '영상 프록시 480p', 'media_type' => 'VIDEO',
                'rendition_type' => 'PROXY_VIDEO', 'variant_key' => '480p',
                'output_format' => 'mp4', 'codec' => 'h264',
                'width' => 854, 'height' => 480, 'bitrate' => 1200, 'audio_bitrate' => 96,
                'frame_rate' => null, 'quality' => 23,
                'params' => ['preset' => 'medium', 'pix_fmt' => 'yuv420p', 'faststart' => true,
                    'maxrate' => '1200k', 'bufsize' => '2400k', 'fps_cap' => 30],
                'is_active' => false,
            ],
            [
                'code' => 'VIDEO_PROXY_360P', 'name' => '영상 프록시 360p', 'media_type' => 'VIDEO',
                'rendition_type' => 'PROXY_VIDEO', 'variant_key' => '360p',
                'output_format' => 'mp4', 'codec' => 'h264',
                'width' => 640, 'height' => 360, 'bitrate' => 700, 'audio_bitrate' => 96,
                'frame_rate' => null, 'quality' => 23,
                'params' => ['preset' => 'medium', 'pix_fmt' => 'yuv420p', 'faststart' => true,
                    'maxrate' => '700k', 'bufsize' => '1400k', 'fps_cap' => 30],
                'is_active' => false,
            ],
            [
                'code' => 'VIDEO_PROXY_HLS_720P', 'name' => '영상 프록시 HLS 720p', 'media_type' => 'VIDEO',
                'rendition_type' => 'PROXY_VIDEO', 'variant_key' => 'hls_720p',
                'output_format' => 'hls', 'codec' => 'h264',
                'width' => 1280, 'height' => 720, 'bitrate' => 2500, 'audio_bitrate' => 128,
                'frame_rate' => null, 'quality' => 23,
                'params' => ['segment_sec' => 6, 'playlist' => 'm3u8', 'pix_fmt' => 'yuv420p', 'fps_cap' => 30],
                'is_active' => false,
            ],
            [
                'code' => 'IMAGE_PROXY_WEBP_2048', 'name' => '이미지 프록시 WebP 2048', 'media_type' => 'IMAGE',
                'rendition_type' => 'PROXY_IMAGE', 'variant_key' => 'default',
                'output_format' => 'webp', 'codec' => 'webp',
                'width' => 2048, 'height' => 2048, 'bitrate' => null, 'audio_bitrate' => null,
                'frame_rate' => null, 'quality' => 82,
                'params' => ['strip' => true, 'autorotate' => true, 'srgb' => true, 'alpha' => true],
                'is_active' => true,
            ],
            [
                'code' => 'IMAGE_THUMBNAIL_WEBP_480', 'name' => '이미지 썸네일 WebP 480', 'media_type' => 'IMAGE',
                'rendition_type' => 'THUMBNAIL', 'variant_key' => 'thumb_480',
                'output_format' => 'webp', 'codec' => 'webp',
                'width' => 480, 'height' => null, 'bitrate' => null, 'audio_bitrate' => null,
                'frame_rate' => null, 'quality' => 75,
                'params' => ['strip' => true, 'autorotate' => true, 'srgb' => true, 'alpha' => true],
                'is_active' => true,
            ],
            [
                'code' => 'AUDIO_PROXY_AAC_128K', 'name' => '오디오 프록시 AAC 128k', 'media_type' => 'AUDIO',
                'rendition_type' => 'PROXY_AUDIO', 'variant_key' => 'default',
                'output_format' => 'm4a', 'codec' => 'aac',
                'width' => null, 'height' => null, 'bitrate' => null, 'audio_bitrate' => 128,
                'frame_rate' => null, 'quality' => null,
                'params' => ['sample_rate' => 44100, 'channels' => 2, 'faststart' => true],
                'is_active' => true,
            ],
            [
                'code' => 'AUDIO_WAVEFORM_JSON', 'name' => '오디오 파형 JSON', 'media_type' => 'AUDIO',
                'rendition_type' => 'WAVEFORM', 'variant_key' => 'wave_json',
                'output_format' => 'json', 'codec' => null,
                'width' => null, 'height' => null, 'bitrate' => null, 'audio_bitrate' => null,
                'frame_rate' => null, 'quality' => null,
                'params' => ['pixels_per_second' => 20, 'bits' => 8],
                'is_active' => true,
            ],
            [
                'code' => 'DOC_PREVIEW_WEBP_144DPI', 'name' => '문서 미리보기 WebP 144dpi', 'media_type' => 'DOC',
                'rendition_type' => 'PAGE_PREVIEW', 'variant_key' => 'page_preview',
                'output_format' => 'webp', 'codec' => 'webp',
                'width' => 1600, 'height' => null, 'bitrate' => null, 'audio_bitrate' => null,
                'frame_rate' => null, 'quality' => 80,
                'params' => ['dpi' => 144, 'page_limit' => 200],
                'is_active' => true,
            ],
            [
                'code' => 'DOC_OCR_KO_EN', 'name' => '문서 OCR 한·영', 'media_type' => 'DOC',
                'rendition_type' => 'OCR_TEXT', 'variant_key' => 'ocr_default',
                'output_format' => 'txt', 'codec' => null,
                'width' => null, 'height' => null, 'bitrate' => null, 'audio_bitrate' => null,
                'frame_rate' => null, 'quality' => null,
                'params' => ['lang' => 'kor+eng', 'psm' => 3, 'page_limit' => 200],
                'is_active' => true,
            ],
            [
                'code' => 'THUMBNAIL_DEFAULT', 'name' => '공통 썸네일 기본', 'media_type' => 'COMMON',
                'rendition_type' => 'THUMBNAIL', 'variant_key' => 'thumb_480',
                'output_format' => 'webp', 'codec' => 'webp',
                'width' => 480, 'height' => null, 'bitrate' => null, 'audio_bitrate' => null,
                'frame_rate' => null, 'quality' => 75,
                'params' => ['video_seek_sec' => 5, 'short_video_middle' => true],
                'is_active' => true,
            ],
            [
                'code' => 'CATALOG_DEFAULT', 'name' => '공통 카탈로그 기본', 'media_type' => 'COMMON',
                'rendition_type' => 'CATALOG', 'variant_key' => 'catalog_default',
                'output_format' => 'webp', 'codec' => 'webp',
                'width' => 320, 'height' => null, 'bitrate' => null, 'audio_bitrate' => null,
                'frame_rate' => null, 'quality' => 70,
                'params' => ['timecodes_sec' => [5, 10, 20, 30], 'interval_sec' => 30, 'max_count' => 20],
                'is_active' => true,
            ],
        ];

        $rows = array_map(function (array $profile) {
            $profile['params'] = json_encode($profile['params']);

            return $profile;
        }, $profiles);

        DB::table('transcode_profiles')->upsert($rows, ['code']);
    }
}
