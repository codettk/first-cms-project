<?php

namespace App\Services\Workflow\Worker\Tools;

/**
 * ffprobe 래퍼 — VERIFY(헤더 파싱)·MA(media_info 추출)·TC(출력 검증)가 공유한다.
 */
class MediaProber
{
    public function __construct(private readonly ToolRunner $runner) {}

    /** @return array<string, mixed>|null 파싱 실패(손상 파일 등)면 null */
    public function probe(string $absolutePath, ?int $timeoutSec = 60): ?array
    {
        $result = $this->runner->run([
            (string) config('workflow.tools.ffprobe'),
            '-v', 'error',
            '-print_format', 'json',
            '-show_format', '-show_streams',
            $absolutePath,
        ], $timeoutSec);

        if (! $result->ok()) {
            return null;
        }

        $decoded = json_decode($result->stdout, true);

        return is_array($decoded) && ($decoded['streams'] ?? []) !== [] ? $decoded : null;
    }

    /** 스트림 구성으로 media_type 판정 — 판별 불가면 null (MA는 PERMANENT 처리) */
    public function detectMediaType(array $probe): ?string
    {
        $codecTypes = array_column($probe['streams'] ?? [], 'codec_type');

        if (in_array('video', $codecTypes, true)) {
            // 커버아트(mjpeg 첨부 이미지) 단독은 오디오로 분류
            $videoStreams = array_filter(
                $probe['streams'],
                fn (array $s) => ($s['codec_type'] ?? null) === 'video'
                    && ($s['disposition']['attached_pic'] ?? 0) !== 1
            );

            if ($videoStreams !== []) {
                return 'VIDEO';
            }
        }

        return in_array('audio', $codecTypes, true) ? 'AUDIO' : null;
    }

    public function durationMs(array $probe): ?int
    {
        $duration = $probe['format']['duration'] ?? null;

        return $duration !== null ? (int) round(((float) $duration) * 1000) : null;
    }

    /** @return array{width: int|null, height: int|null, codec: string|null} */
    public function primaryVideoStream(array $probe): array
    {
        foreach ($probe['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) === 'video'
                && ($stream['disposition']['attached_pic'] ?? 0) !== 1) {
                return [
                    'width' => $stream['width'] ?? null,
                    'height' => $stream['height'] ?? null,
                    'codec' => $stream['codec_name'] ?? null,
                ];
            }
        }

        return ['width' => null, 'height' => null, 'codec' => null];
    }
}
