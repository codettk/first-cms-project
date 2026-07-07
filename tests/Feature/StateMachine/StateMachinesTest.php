<?php

namespace Tests\Feature\StateMachine;

use App\Enums\ContentStatus;
use App\Enums\IndexStatus;
use App\Enums\InstanceStatus;
use App\Enums\JobStatus;
use App\Enums\WorkerStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Content;
use App\Models\SearchIndexState;
use App\Models\WorkflowInstance;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\StateMachine\ContentStateMachine;
use App\Services\Workflow\StateMachine\JobStateMachine;
use App\Services\Workflow\StateMachine\SearchIndexStateMachine;
use App\Services\Workflow\StateMachine\WorkerStateMachine;
use App\Services\Workflow\StateMachine\WorkflowInstanceStateMachine;
use App\Services\Workflow\TransitionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * State Machine Spec §3·§4·§13·§14 — 나머지 4종 상태 기계 + 전이표 정합성 + TransitionRunner.
 */
class StateMachinesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_can_transition_matches_enum_transition_maps(): void
    {
        $pairs = [
            [app(JobStateMachine::class), JobStatus::class],
            [app(ContentStateMachine::class), ContentStatus::class],
            [app(WorkflowInstanceStateMachine::class), InstanceStatus::class],
            [app(WorkerStateMachine::class), WorkerStatus::class],
            [app(SearchIndexStateMachine::class), IndexStatus::class],
        ];

        foreach ($pairs as [$machine, $enum]) {
            $map = $enum::transitionMap();
            $statuses = array_map(fn ($case) => $case->value, $enum::cases());

            foreach ($statuses as $from) {
                foreach ($statuses as $to) {
                    $expected = in_array($to, $map[$from] ?? [], true);
                    $this->assertSame(
                        $expected,
                        $machine->canTransition($from, $to),
                        $machine::class." canTransition({$from}, {$to})가 enum 전이표와 다르다"
                    );
                }
            }
        }
    }

    public function test_content_registered_to_processing(): void
    {
        $content = Content::factory()->registered()->create();

        $result = DB::transaction(
            fn () => app(ContentStateMachine::class)->transition($content, 'PROCESSING', 'scheduler')
        );

        $this->assertTrue($result->ok);
        $this->assertSame('PROCESSING', $content->fresh()->status->value);
    }

    public function test_content_deleted_is_terminal(): void
    {
        $content = Content::factory()->deleted()->create();

        $this->expectException(InvalidStateTransitionException::class);
        DB::transaction(
            fn () => app(ContentStateMachine::class)->transition($content, 'READY', 'admin:1')
        );
    }

    public function test_instance_running_to_success_with_finished_at(): void
    {
        $instance = WorkflowInstance::factory()->create();

        $result = DB::transaction(fn () => app(WorkflowInstanceStateMachine::class)->transition(
            $instance, 'SUCCESS', 'worker:1', null, ['finished_at' => now()]
        ));

        $this->assertTrue($result->ok);
        $fresh = $instance->fresh();
        $this->assertSame('SUCCESS', $fresh->status->value);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_instance_terminal_states_cannot_return_to_running(): void
    {
        $instance = WorkflowInstance::factory()->create(['status' => InstanceStatus::Success]);

        $this->expectException(InvalidStateTransitionException::class);
        DB::transaction(
            fn () => app(WorkflowInstanceStateMachine::class)->transition($instance, 'RUNNING', 'admin:1')
        );
    }

    public function test_worker_online_to_busy_and_error_requires_admin_release(): void
    {
        $worker = WorkflowWorkerAgent::factory()->create(['status' => WorkerStatus::Online]);
        $machine = app(WorkerStateMachine::class);

        $result = DB::transaction(fn () => $machine->transition($worker, 'BUSY', "worker:{$worker->id}"));
        $this->assertTrue($result->ok);

        // DISABLED에서 BUSY 직행 금지 — ONLINE 경유
        $disabled = WorkflowWorkerAgent::factory()->create(['status' => WorkerStatus::Disabled]);
        $this->expectException(InvalidStateTransitionException::class);
        DB::transaction(fn () => $machine->transition($disabled, 'BUSY', 'admin:1'));
    }

    public function test_search_index_pending_to_indexed(): void
    {
        $content = Content::factory()->processing()->create();
        $state = SearchIndexState::create([
            'content_id' => $content->id,
            'status' => IndexStatus::Pending,
        ]);

        $result = DB::transaction(fn () => app(SearchIndexStateMachine::class)->transition(
            $state, 'INDEXED', 'worker:1', null, ['indexed_at' => now(), 'index_version' => 1]
        ));

        $this->assertTrue($result->ok);
        $fresh = $state->fresh();
        $this->assertSame('INDEXED', $fresh->status->value);
        $this->assertSame(1, (int) $fresh->index_version);
    }

    public function test_transition_runner_owns_transaction_for_standalone_transition(): void
    {
        $content = Content::factory()->registered()->create();

        $result = app(TransitionRunner::class)->run(
            app(ContentStateMachine::class), $content, 'PROCESSING', 'scheduler'
        );

        $this->assertTrue($result->ok);
        $this->assertSame('PROCESSING', $content->fresh()->status->value);
    }
}
