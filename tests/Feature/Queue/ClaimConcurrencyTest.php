<?php

namespace Tests\Feature\Queue;

use App\Models\WorkflowJob;
use App\Models\WorkflowJobLock;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Queue\JobClaimService;
use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Queue Worker Spec §5·§6 — FOR UPDATE SKIP LOCKED · locks PK 이중 lease 차단 ·
 * advisory lock 단일 Scheduler. 트랜잭션 격리를 실제로 검증해야 하므로
 * RefreshDatabase(트랜잭션 래핑) 대신 DatabaseTruncation을 사용한다(커밋 필요).
 */
class ClaimConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const string SECOND_CONNECTION = 'pgsql_concurrent';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        Config::set(
            'database.connections.'.self::SECOND_CONNECTION,
            config('database.connections.'.config('database.default'))
        );
    }

    protected function tearDown(): void
    {
        DB::purge(self::SECOND_CONNECTION);
        parent::tearDown();
    }

    public function test_skip_locked_prevents_claiming_a_row_held_by_another_transaction(): void
    {
        $worker = WorkflowWorkerAgent::factory()->online()->create(['supported_job_types' => ['TM']]);
        $high = WorkflowJob::factory()->ready()->create(['priority' => 200]);
        $low = WorkflowJob::factory()->ready()->create(['priority' => 100]);

        $second = DB::connection(self::SECOND_CONNECTION);
        $second->beginTransaction();

        try {
            // 다른 트랜잭션이 우선순위 높은 행을 선점
            $second->select('SELECT id FROM workflow_jobs WHERE id = ? FOR UPDATE', [$high->id]);

            $claimed = app(JobClaimService::class)->tryClaim($worker);

            $this->assertNotNull($claimed);
            $this->assertSame($low->id, $claimed->id, 'SKIP LOCKED이 선점된 행을 건너뛰지 않았다');
        } finally {
            $second->rollBack();
        }
    }

    public function test_lock_pk_blocks_double_lease(): void
    {
        $holder = WorkflowWorkerAgent::factory()->online()->create();
        $claimer = WorkflowWorkerAgent::factory()->online()->create(['supported_job_types' => ['TM']]);
        $job = WorkflowJob::factory()->ready()->create();

        // 이미 다른 Worker의 lock 행이 존재 (비정상 상황 재현)
        WorkflowJobLock::create([
            'job_id' => $job->id,
            'locked_by_worker_id' => $holder->id,
            'locked_until' => now()->addMinutes(10),
        ]);

        $claimed = app(JobClaimService::class)->tryClaim($claimer);

        $this->assertNull($claimed, 'locks PK 충돌 시 Claim은 롤백 후 null이어야 한다');
        $fresh = $job->fresh();
        $this->assertSame('READY', $fresh->status->value);
        $this->assertNull($fresh->worker_id);
        $this->assertSame(1, WorkflowJobLock::where('job_id', $job->id)->count());
    }

    public function test_sequential_claims_take_distinct_jobs_in_priority_order(): void
    {
        $a = WorkflowWorkerAgent::factory()->online()->create(['supported_job_types' => ['TM']]);
        $b = WorkflowWorkerAgent::factory()->online()->create(['supported_job_types' => ['TM']]);

        $urgent = WorkflowJob::factory()->ready()->create(['priority' => 300]);
        $manual = WorkflowJob::factory()->ready()->create(['priority' => 200]);
        $normal = WorkflowJob::factory()->ready()->create(['priority' => 100]);

        $claim = app(JobClaimService::class);
        $first = $claim->tryClaim($a);
        $second = $claim->tryClaim($b);
        $third = $claim->tryClaim($a->refresh()); // BUSY 상태여도 Claim 자체는 가능(1proc=1job 규율은 루프가 보장)

        $ids = [$first->id, $second->id, $third->id];
        $this->assertSame([$urgent->id, $manual->id, $normal->id], $ids);
        $this->assertCount(3, array_unique($ids), '중복 Claim 발생');
    }

    public function test_claim_ignores_retry_jobs(): void
    {
        // ADR-0003: RETRY는 Scheduler 재개(READY 전환) 후에만 소비된다
        $worker = WorkflowWorkerAgent::factory()->online()->create(['supported_job_types' => ['TM']]);
        WorkflowJob::factory()->create([
            'status' => 'RETRY', 'next_attempt_at' => now()->subMinute(),
        ]);

        $this->assertNull(app(JobClaimService::class)->tryClaim($worker));
    }

    public function test_claim_respects_supported_job_types(): void
    {
        $worker = WorkflowWorkerAgent::factory()->online()->create([
            'supported_job_types' => ['VERIFY', 'MA'],
        ]);
        WorkflowJob::factory()->ready()->create(); // TM

        $this->assertNull(app(JobClaimService::class)->tryClaim($worker));
    }

    public function test_claim_respects_next_attempt_at(): void
    {
        $worker = WorkflowWorkerAgent::factory()->online()->create(['supported_job_types' => ['TM']]);
        WorkflowJob::factory()->ready()->create(['next_attempt_at' => now()->addMinutes(10)]);

        $this->assertNull(app(JobClaimService::class)->tryClaim($worker));
    }

    public function test_advisory_lock_guarantees_single_scheduler(): void
    {
        $scheduler = app(WorkflowSchedulerService::class);

        $this->assertTrue($scheduler->tryAcquireAdvisoryLock());

        try {
            $second = DB::connection(self::SECOND_CONNECTION);
            $blocked = $second->selectOne(
                'SELECT pg_try_advisory_lock(?) AS ok',
                [WorkflowSchedulerService::ADVISORY_LOCK_KEY]
            );

            $this->assertFalse((bool) $blocked->ok, '두 번째 Scheduler가 advisory lock을 획득했다');
        } finally {
            $scheduler->releaseAdvisoryLock();
        }
    }
}
