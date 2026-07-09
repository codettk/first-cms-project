<?php

namespace App\Services\Workflow\Storage;

use App\Exceptions\ProfileInactiveException;
use App\Models\TranscodeProfile;
use DomainException;

/**
 * transcode profile 조회 + params → 도구 인자 변환 — Transcode Profile Spec §15.
 * 프로파일→인자 배열 매핑의 단일 클래스. 쉘 문자열 금지 — 인자 배열만 (Worker Agent Spec §10).
 */
class ProfileCompiler
{
    /** code로 조회 — 비활성이면 실행 거부(재시도 안 함) */
    public function loadByCode(string $code): TranscodeProfile
    {
        $profile = TranscodeProfile::query()->where('code', $code)->first();

        if ($profile === null) {
            throw new DomainException("transcode profile not found: {$code}");
        }

        if (! $profile->is_active) {
            throw new ProfileInactiveException($code);
        }

        return $profile;
    }

    /**
     * VIDEO 프록시 ffmpeg 인자 — VIDEO_PROXY_720P 기준 (CPU 경로).
     * GPU 경로는 params.gpu_codec 존재 + Worker gpu.enabled일 때만 (M6 이후 운용).
     *
     * @return list<string>
     */
    public function ffmpegVideoArgs(TranscodeProfile $profile, string $inputPath, string $outputPath): array
    {
        $params = $profile->params ?? [];

        $args = ['-y', '-i', $inputPath, '-c:v', $this->videoEncoder($profile)];

        if ($profile->quality !== null) {
            $args = [...$args, '-crf', (string) $profile->quality];
        }

        if (isset($params['preset'])) {
            $args = [...$args, '-preset', (string) $params['preset']];
        }

        if (isset($params['maxrate'])) {
            $args = [...$args, '-maxrate', (string) $params['maxrate']];
        }

        if (isset($params['bufsize'])) {
            $args = [...$args, '-bufsize', (string) $params['bufsize']];
        }

        if ($profile->width !== null) {
            // 원본이 상한보다 작으면 업스케일하지 않는다 (-2: 짝수 높이 자동)
            $args = [...$args, '-vf', "scale='min({$profile->width},iw)':-2"];
        }

        if (isset($params['fps_cap'])) {
            $args = [...$args, '-r', (string) $params['fps_cap']];
        }

        if (isset($params['pix_fmt'])) {
            $args = [...$args, '-pix_fmt', (string) $params['pix_fmt']];
        }

        $args = [...$args, '-c:a', 'aac'];

        if ($profile->audio_bitrate !== null) {
            $args = [...$args, '-b:a', "{$profile->audio_bitrate}k"];
        }

        if (($params['faststart'] ?? false) === true) {
            $args = [...$args, '-movflags', '+faststart'];
        }

        // TC 진행률 파싱용 (Queue Worker Spec §10)
        return [...$args, '-progress', 'pipe:1', $outputPath];
    }

    /**
     * 대표 썸네일/카탈로그 프레임 추출 인자 — CA job (THUMBNAIL_DEFAULT/CATALOG_DEFAULT).
     *
     * @return list<string>
     */
    public function ffmpegFrameArgs(TranscodeProfile $profile, string $inputPath, string $outputPath, float $seekSec): array
    {
        $args = ['-y', '-ss', (string) $seekSec, '-i', $inputPath, '-frames:v', '1'];

        if ($profile->width !== null) {
            $args = [...$args, '-vf', "scale='min({$profile->width},iw)':-2"];
        }

        if ($profile->quality !== null) {
            $args = [...$args, '-quality', (string) $profile->quality];
        }

        return [...$args, $outputPath];
    }

    /**
     * AUDIO 프록시 ffmpeg 인자 — AUDIO_PROXY_AAC_128K 기준 (Transcode Profile Spec §6·§11).
     *
     * @return list<string>
     */
    public function ffmpegAudioArgs(TranscodeProfile $profile, string $inputPath, string $outputPath): array
    {
        $params = $profile->params ?? [];

        $args = ['-y', '-i', $inputPath, '-vn', '-c:a', (string) ($profile->codec ?? 'aac')];

        if ($profile->audio_bitrate !== null) {
            $args = [...$args, '-b:a', "{$profile->audio_bitrate}k"];
        }

        if (isset($params['sample_rate'])) {
            $args = [...$args, '-ar', (string) $params['sample_rate']];
        }

        if (isset($params['channels'])) {
            $args = [...$args, '-ac', (string) $params['channels']];
        }

        if (($params['faststart'] ?? false) === true) {
            $args = [...$args, '-movflags', '+faststart'];
        }

        // 진행률 파싱용 (Queue Worker Spec §10)
        return [...$args, '-progress', 'pipe:1', $outputPath];
    }

    /**
     * IMAGE 프록시/썸네일 vipsthumbnail 인자 — Transcode Profile Spec §12.
     * autorotate(EXIF 픽셀 적용)·strip(GPS 등 메타 제거)·sRGB 변환·축소만(업스케일 금지).
     *
     * @return list<string>
     */
    public function vipsThumbnailArgs(TranscodeProfile $profile, string $inputPath, string $outputPath): array
    {
        $params = $profile->params ?? [];

        $outputSpec = [];
        if ($profile->quality !== null) {
            $outputSpec[] = "Q={$profile->quality}";
        }
        if (($params['strip'] ?? false) === true) {
            $outputSpec[] = 'strip';
        }

        $width = $profile->width ?? 2048;
        $height = $profile->height ?? $width;

        $args = [
            $inputPath,
            '-o', $outputSpec === [] ? $outputPath : $outputPath.'['.implode(',', $outputSpec).']',
            '--size', "{$width}x{$height}>", // '>' — 축소만 (Spec §12 업스케일 금지)
        ];

        if (($params['autorotate'] ?? false) === true) {
            $args[] = '--rotate';
        }

        if (($params['srgb'] ?? false) === true) {
            $args = [...$args, '--eprofile', 'srgb'];
        }

        return $args;
    }

    /**
     * WAVEFORM audiowaveform 인자 — AUDIO_WAVEFORM_JSON 기준 (Transcode Profile Spec §6).
     *
     * @return list<string>
     */
    public function audiowaveformArgs(TranscodeProfile $profile, string $inputPath, string $outputPath): array
    {
        $params = $profile->params ?? [];

        $args = ['-i', $inputPath, '-o', $outputPath];

        if (isset($params['pixels_per_second'])) {
            $args = [...$args, '--pixels-per-second', (string) $params['pixels_per_second']];
        }

        if (isset($params['bits'])) {
            $args = [...$args, '-b', (string) $params['bits']];
        }

        return $args;
    }

    /**
     * OCR tesseract 인자 — DOC_OCR_KO_EN 기준 (Job Type Def §3.9).
     * 출력은 word 단위 신뢰도를 포함한 tsv — 도구가 {outputBase}.tsv를 생성한다.
     *
     * @return list<string>
     */
    public function tesseractArgs(TranscodeProfile $profile, string $inputPath, string $outputBase): array
    {
        $params = $profile->params ?? [];

        $args = [$inputPath, $outputBase];

        if (isset($params['lang'])) {
            $args = [...$args, '-l', (string) $params['lang']];
        }

        if (isset($params['psm'])) {
            $args = [...$args, '--psm', (string) $params['psm']];
        }

        return [...$args, 'tsv'];
    }

    private function videoEncoder(TranscodeProfile $profile): string
    {
        return match ($profile->codec) {
            'h264', null => 'libx264',
            'h265', 'hevc' => 'libx265',
            default => (string) $profile->codec,
        };
    }
}
