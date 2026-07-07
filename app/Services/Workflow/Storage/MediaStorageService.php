<?php

namespace App\Services\Workflow\Storage;

use App\Enums\StorageZone;
use App\Exceptions\MasterZoneProtectionException;
use RuntimeException;

/**
 * zone별 경로 해석·임시 파일·atomic rename — Worker Agent Spec §15.
 *
 * 모든 쓰기는 {zone}/.tmp/{job_id}/에 먼저 쓰고 검증 후 최종 경로로 atomic rename.
 * MASTER zone은 TM의 최초 쓰기(promoteToMaster) 외 수정·삭제 API를 제공하지 않는다.
 */
class MediaStorageService
{
    public function zonePath(StorageZone|string $zone): string
    {
        $zone = $zone instanceof StorageZone ? $zone->value : strtoupper($zone);

        // rendition.path는 스펙 §12 관례대로 zone 폴더 프리픽스를 포함한다
        // (master/{yyyy}/…, proxy/{yyyy}/…). 따라서 기본 루트는 공유 루트이며,
        // zone별 마운트 사용 시 env가 해당 zone 폴더를 포함하는 상위 경로를 가리켜야 한다.
        $configured = config("workflow.storage.zones.{$zone}");
        $path = $configured ?: rtrim((string) config('workflow.storage.root'), '/\\');

        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }

    public function absolutePath(StorageZone|string $zone, string $relativePath): string
    {
        $this->assertSafeRelativePath($relativePath);

        return $this->zonePath($zone).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    /** job 전용 임시 작업 디렉터리 — {zone}/.tmp/{job_id}/ */
    public function tempWorkDir(StorageZone|string $zone, int $jobId): string
    {
        $dir = $this->zonePath($zone).DIRECTORY_SEPARATOR.'.tmp'.DIRECTORY_SEPARATOR.$jobId;

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** 임시 작업 파일 경로 생성 (파일은 호출자가 기록) */
    public function tempFilePath(StorageZone|string $zone, int $jobId, string $filename): string
    {
        $this->assertSafeRelativePath($filename);

        return $this->tempWorkDir($zone, $jobId).DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * 검증 완료된 임시 파일을 최종 경로로 atomic rename.
     * MASTER zone은 거부 — TM은 promoteToMaster를 사용한다.
     */
    public function promote(StorageZone|string $zone, string $tempAbsolutePath, string $targetRelativePath): string
    {
        $this->assertNotMasterMutation($zone, $targetRelativePath);

        return $this->rename($zone, $tempAbsolutePath, $targetRelativePath, allowOverwrite: true);
    }

    /**
     * TM 전용 — MASTER 최초 쓰기 예외 메서드. 기존 파일 덮어쓰기는 거부한다
     * (TM은 attempt마다 uuid8 경로를 새로 생성한다 — Worker Agent Spec §9).
     */
    public function promoteToMaster(string $tempAbsolutePath, string $targetRelativePath): string
    {
        return $this->rename(StorageZone::Master, $tempAbsolutePath, $targetRelativePath, allowOverwrite: false);
    }

    /** 웹 표출 zone(TEMP 포함)의 파일 삭제 — MASTER/ARCHIVE 금지 */
    public function delete(StorageZone|string $zone, string $relativePath): void
    {
        $this->assertNotMasterMutation($zone, $relativePath, 'delete');

        $path = $this->absolutePath($zone, $relativePath);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** job 종료 시(성공/실패 무관) 임시 작업 디렉터리 정리 — 전 zone 대상 */
    public function cleanTempWorkDirs(int $jobId): void
    {
        foreach (StorageZone::cases() as $zone) {
            $dir = $this->zonePath($zone).DIRECTORY_SEPARATOR.'.tmp'.DIRECTORY_SEPARATOR.$jobId;
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }
    }

    public function assertNotMasterMutation(StorageZone|string $zone, string $path, string $operation = 'write'): void
    {
        $zoneValue = $zone instanceof StorageZone ? $zone->value : strtoupper($zone);

        if (in_array($zoneValue, [StorageZone::Master->value, StorageZone::Archive->value], true)) {
            throw new MasterZoneProtectionException($operation, "{$zoneValue}/{$path}");
        }
    }

    public function fileSize(string $absolutePath): int
    {
        $size = is_file($absolutePath) ? filesize($absolutePath) : false;

        if ($size === false) {
            throw new RuntimeException("cannot stat file: {$absolutePath}");
        }

        return $size;
    }

    public function checksumSha256(string $absolutePath): string
    {
        $hash = hash_file('sha256', $absolutePath);

        if ($hash === false) {
            throw new RuntimeException("cannot hash file: {$absolutePath}");
        }

        return $hash;
    }

    private function rename(StorageZone|string $zone, string $tempAbsolutePath, string $targetRelativePath, bool $allowOverwrite): string
    {
        if (! is_file($tempAbsolutePath)) {
            throw new RuntimeException("temp file missing: {$tempAbsolutePath}");
        }

        $target = $this->absolutePath($zone, $targetRelativePath);

        $targetDir = dirname($target);
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        if (file_exists($target)) {
            if (! $allowOverwrite) {
                $zoneValue = $zone instanceof StorageZone ? $zone->value : strtoupper($zone);
                throw new MasterZoneProtectionException('overwrite', "{$zoneValue}/{$targetRelativePath}");
            }

            // POSIX rename은 원자적 덮어쓰기 — Windows(개발 환경)만 선삭제 필요
            if (PHP_OS_FAMILY === 'Windows') {
                unlink($target);
            }
        }

        if (! rename($tempAbsolutePath, $target)) {
            throw new RuntimeException("atomic rename failed: {$tempAbsolutePath} -> {$target}");
        }

        return $target;
    }

    private function assertSafeRelativePath(string $path): void
    {
        if (str_contains($path, '..') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new RuntimeException("unsafe path rejected: {$path}");
        }
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
