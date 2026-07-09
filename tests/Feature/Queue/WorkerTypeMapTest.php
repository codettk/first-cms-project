<?php

namespace Tests\Feature\Queue;

use App\Models\WorkflowWorkerAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * workflow:worker 기본 supported_job_types — Worker Agent Spec §3 전체 매핑.
 * STT·AI_ANALYSIS는 스펙에 worker 배정이 없으므로 어떤 기본 매핑에도 없어야 한다.
 */
class WorkerTypeMapTest extends TestCase
{
    use RefreshDatabase;

    private const array SPEC_TYPE_MAP = [
        'TM' => ['TM'],
        'GENERAL' => ['VERIFY', 'MA', 'PUBLISH', 'CLEANUP'],
        'TRANSCODE' => ['TC'],
        'IMAGE' => ['IMAGE_TC', 'CA'],
        'AUDIO' => ['AUDIO_TC', 'WAVEFORM'],
        'DOCUMENT' => ['DOC_PREVIEW', 'TEXT_EXTRACT'],
        'OCR' => ['OCR'],
        'INDEX' => ['INDEX'],
    ];

    public function test_each_worker_type_registers_spec_default_job_types(): void
    {
        foreach (self::SPEC_TYPE_MAP as $type => $expected) {
            $name = 'map-test-'.strtolower($type);

            $this->artisan('workflow:worker', [
                '--name' => $name,
                '--worker-type' => $type,
                '--once' => true,
            ])->assertSuccessful();

            $agent = WorkflowWorkerAgent::query()->where('worker_name', $name)->firstOrFail();
            $this->assertSame($expected, $agent->supported_job_types, "worker_type {$type} 기본 매핑 불일치");
        }
    }

    public function test_no_default_mapping_contains_unassigned_types(): void
    {
        foreach (self::SPEC_TYPE_MAP as $type => $jobTypes) {
            $this->assertEmpty(
                array_intersect(['STT', 'AI_ANALYSIS'], $jobTypes),
                "worker_type {$type}에 미배정 유형이 포함됨",
            );
        }
    }
}
