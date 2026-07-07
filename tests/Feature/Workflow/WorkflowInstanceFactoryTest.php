<?php

namespace Tests\Feature\Workflow;

use App\Enums\JobType;
use App\Models\Content;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Services\Workflow\WorkflowInstanceFactory;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Roadmap Phase 2 — 템플릿 기반 인스턴스/job/dependency 생성 · DAG 검증 · RUNNING 1건 제약.
 */
class WorkflowInstanceFactoryTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowInstanceFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->factory = app(WorkflowInstanceFactory::class);
    }

    private function videoTemplate(): WorkflowTemplate
    {
        return WorkflowTemplate::where('code', 'VIDEO_INGEST')->firstOrFail();
    }

    public function test_video_template_creates_8_jobs_with_dependencies(): void
    {
        $content = Content::factory()->registered()->create();

        $instance = DB::transaction(
            fn () => $this->factory->createForContent($content, $this->videoTemplate())
        );

        $jobs = $instance->jobs()->get();
        $this->assertCount(8, $jobs);
        $this->assertSame('RUNNING', $instance->fresh()->status->value);

        foreach ($jobs as $job) {
            $this->assertSame('WAITING', $job->status->value);
            $this->assertSame(100, $job->priority);
            $this->assertTrue($job->is_required);
        }

        // TM만 선행 없음 — 나머지 7개는 각 1건의 의존 행 (VIDEO_INGEST 직렬 체인)
        $dependencyCount = DB::table('workflow_job_dependencies')
            ->whereIn('job_id', $jobs->pluck('id'))
            ->count();
        $this->assertSame(7, $dependencyCount);

        $tm = $jobs->firstWhere('job_type', JobType::Tm);
        $this->assertCount(0, $tm->dependsOn);

        $verify = $jobs->firstWhere('job_type', JobType::Verify);
        $this->assertSame([$tm->id], $verify->dependsOn->pluck('id')->all());
    }

    public function test_step_config_becomes_job_payload(): void
    {
        $content = Content::factory()->registered()->create();

        $instance = DB::transaction(
            fn () => $this->factory->createForContent($content, $this->videoTemplate())
        );

        $tc = $instance->jobs()->where('job_type', 'TC')->firstOrFail();
        $this->assertSame(['profile' => 'VIDEO_PROXY_720P'], $tc->payload);
        $this->assertSame(2, $tc->max_retry);
        $this->assertSame(7200, $tc->timeout_sec);
    }

    public function test_creation_histories_written_with_null_from_status(): void
    {
        $content = Content::factory()->registered()->create();

        $instance = DB::transaction(
            fn () => $this->factory->createForContent($content, $this->videoTemplate())
        );

        $historyCount = DB::table('workflow_job_histories')
            ->whereIn('job_id', $instance->jobs()->pluck('id'))
            ->whereNull('from_status')
            ->where('to_status', 'WAITING')
            ->count();

        $this->assertSame(8, $historyCount);
    }

    public function test_priority_applies_to_all_jobs(): void
    {
        $content = Content::factory()->registered()->create();

        $instance = DB::transaction(
            fn () => $this->factory->createForContent($content, $this->videoTemplate(), priority: 200, actor: 'admin:1')
        );

        $this->assertSame([200], $instance->jobs()->distinct()->pluck('priority')->all());
    }

    public function test_second_running_instance_for_same_content_is_rejected(): void
    {
        $content = Content::factory()->registered()->create();

        DB::transaction(fn () => $this->factory->createForContent($content, $this->videoTemplate()));

        $this->expectException(QueryException::class); // uq_instances_running partial unique
        DB::transaction(fn () => $this->factory->createForContent($content, $this->videoTemplate()));
    }

    public function test_dependency_cycle_is_rejected(): void
    {
        $template = WorkflowTemplate::factory()->create();
        WorkflowTemplateStep::factory()->create([
            'template_id' => $template->id, 'job_type' => JobType::Tm,
            'step_order' => 1, 'depends_on' => ['VERIFY'],
        ]);
        WorkflowTemplateStep::factory()->create([
            'template_id' => $template->id, 'job_type' => JobType::Verify,
            'step_order' => 2, 'depends_on' => ['TM'],
        ]);

        $content = Content::factory()->registered()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cycle');
        DB::transaction(fn () => $this->factory->createForContent($content, $template));
    }

    public function test_unknown_dependency_reference_is_rejected(): void
    {
        $template = WorkflowTemplate::factory()->create();
        WorkflowTemplateStep::factory()->create([
            'template_id' => $template->id, 'job_type' => JobType::Ca,
            'step_order' => 1, 'depends_on' => ['MA'],
        ]);

        $content = Content::factory()->registered()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('unknown step');
        DB::transaction(fn () => $this->factory->createForContent($content, $template));
    }
}
