<?php

namespace Tests\Feature\Storage;

use App\Enums\StorageZone;
use App\Exceptions\MasterZoneProtectionException;
use App\Services\Workflow\Storage\MediaStorageService;
use Tests\TestCase;

/**
 * Worker Agent Spec §15 — .tmp 쓰기 → atomic rename · MASTER 변조 차단 · 임시 정리.
 */
class MediaStorageServiceTest extends TestCase
{
    private MediaStorageService $storage;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf-storage-test-'.getmypid().'-'.uniqid();
        config(['workflow.storage.root' => $this->root]);
        config(['workflow.storage.zones' => array_fill_keys(
            ['TEMP', 'MASTER', 'PROXY', 'THUMBNAIL', 'CATALOG', 'DOCUMENT', 'ARCHIVE'], null
        )]);

        $this->storage = new MediaStorageService;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec(PHP_OS_FAMILY === 'Windows'
                ? 'rmdir /s /q "'.$this->root.'"'
                : 'rm -rf "'.$this->root.'"');
        }

        parent::tearDown();
    }

    private function makeTempFile(StorageZone $zone, int $jobId, string $name, string $contents): string
    {
        $path = $this->storage->tempFilePath($zone, $jobId, $name);
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_write_to_tmp_then_promote_moves_file_atomically(): void
    {
        $tmp = $this->makeTempFile(StorageZone::Proxy, 7, 'out.mp4', 'proxy-bytes');

        $final = $this->storage->promote(StorageZone::Proxy, $tmp, 'proxy/2026/07/1_720p.mp4');

        $this->assertFileExists($final);
        $this->assertFileDoesNotExist($tmp);
        $this->assertSame('proxy-bytes', file_get_contents($final));
    }

    public function test_promote_overwrites_existing_web_facing_file(): void
    {
        $first = $this->makeTempFile(StorageZone::Proxy, 7, 'a.mp4', 'v1');
        $this->storage->promote(StorageZone::Proxy, $first, 'proxy/1.mp4');

        $second = $this->makeTempFile(StorageZone::Proxy, 7, 'b.mp4', 'v2');
        $final = $this->storage->promote(StorageZone::Proxy, $second, 'proxy/1.mp4');

        $this->assertSame('v2', file_get_contents($final)); // 재실행 upsert — 덮어쓰기 허용
    }

    public function test_generic_promote_to_master_zone_is_blocked(): void
    {
        $tmp = $this->makeTempFile(StorageZone::Master, 7, 'm.mp4', 'master');

        $this->expectException(MasterZoneProtectionException::class);
        $this->storage->promote(StorageZone::Master, $tmp, 'master/2026/07/1.mp4');
    }

    public function test_promote_to_master_allows_initial_write_only(): void
    {
        $tmp = $this->makeTempFile(StorageZone::Master, 7, 'm.mp4', 'master-v1');
        $final = $this->storage->promoteToMaster($tmp, 'master/2026/07/1_abc.mp4');
        $this->assertFileExists($final);

        // 동일 경로 재쓰기(변조)는 차단
        $tmp2 = $this->makeTempFile(StorageZone::Master, 8, 'm.mp4', 'master-v2');
        $this->expectException(MasterZoneProtectionException::class);
        $this->storage->promoteToMaster($tmp2, 'master/2026/07/1_abc.mp4');
    }

    public function test_delete_on_master_and_archive_is_blocked(): void
    {
        $this->expectException(MasterZoneProtectionException::class);
        $this->storage->delete(StorageZone::Master, 'master/2026/07/1_abc.mp4');
    }

    public function test_delete_on_temp_zone_is_allowed(): void
    {
        $tmp = $this->makeTempFile(StorageZone::Temp, 7, 'up.bin', 'upload');
        $final = $this->storage->promote(StorageZone::Temp, $tmp, 'uploads/up.bin');

        $this->storage->delete(StorageZone::Temp, 'uploads/up.bin');

        $this->assertFileDoesNotExist($final);
    }

    public function test_clean_temp_work_dirs_removes_job_scratch_space(): void
    {
        $this->makeTempFile(StorageZone::Proxy, 42, 'partial.mp4', 'x');
        $this->makeTempFile(StorageZone::Thumbnail, 42, 'partial.webp', 'y');

        $this->storage->cleanTempWorkDirs(42);

        $this->assertDirectoryDoesNotExist($this->storage->zonePath(StorageZone::Proxy).DIRECTORY_SEPARATOR.'.tmp'.DIRECTORY_SEPARATOR.'42');
        $this->assertDirectoryDoesNotExist($this->storage->zonePath(StorageZone::Thumbnail).DIRECTORY_SEPARATOR.'.tmp'.DIRECTORY_SEPARATOR.'42');
    }

    public function test_path_traversal_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->storage->absolutePath(StorageZone::Proxy, '../master/steal.mp4');
    }

    public function test_checksum_and_size_helpers(): void
    {
        $tmp = $this->makeTempFile(StorageZone::Temp, 7, 'sum.bin', 'hello');

        $this->assertSame(5, $this->storage->fileSize($tmp));
        $this->assertSame(hash('sha256', 'hello'), $this->storage->checksumSha256($tmp));
    }
}
