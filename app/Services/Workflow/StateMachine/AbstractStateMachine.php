<?php

namespace App\Services\Workflow\StateMachine;

use App\Exceptions\InvalidStateTransitionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 모든 상태 전이의 단일 관문 — State Machine Spec §16 · Controller Service Spec §7.
 *
 * 트랜잭션 소유권: 자체 트랜잭션을 열지 않는다. 최상위 트랜잭션은
 * CommandService(관리자 조작)·JobClaimService(Worker)·Scheduler가 소유하고,
 * 단독 전이는 TransitionRunner가 소유한다. 여기서는 CAS UPDATE + history INSERT만 수행한다.
 *
 * updated_at은 DB 트리거(set_updated_at)가 갱신하므로 SET 하지 않는다.
 */
abstract class AbstractStateMachine
{
    /**
     * 허용 전이표 — 상태 enum의 transitionMap()을 단일 소스로 사용한다
     * (enum ↔ allowed_transitions seed ↔ trigger 3곳 일치는 테스트로 검증).
     *
     * @return array<string, list<string>>
     */
    abstract protected function transitions(): array;

    /** 예외 메시지·409 응답에 쓰이는 대상 이름 (예: job, content) */
    abstract protected function targetName(): string;

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, $this->transitions()[$from] ?? [], true);
    }

    public function assertTransition(string $from, string $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidStateTransitionException($this->targetName(), $from, $to);
        }
    }

    /**
     * CAS 전이 (WHERE id=? AND status=?). 0행 갱신은 동시성 충돌 —
     * 예외가 아닌 TransitionResult::conflict를 반환한다.
     *
     * @param array<string, mixed> $extra 전이와 원자적으로 갱신할 부가 컬럼 (finished_at 등)
     */
    public function transition(Model $model, string $to, string $actor, ?string $note = null, array $extra = []): TransitionResult
    {
        $from = $model->status->value;
        $this->assertTransition($from, $to);
        $this->assertInTransaction();

        $updated = $this->casUpdate($model, $from, $to, $extra);
        if ($updated === 0) {
            return TransitionResult::conflict($model->fresh()->status->value);
        }

        $this->writeHistory($model, $from, $to, $actor, $note);

        return TransitionResult::ok($from, $to);
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $andWhere fencing 등 추가 CAS 조건 (worker_id 등)
     */
    protected function casUpdate(Model $model, string $from, string $to, array $extra = [], array $andWhere = []): int
    {
        $query = DB::table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->where('status', $from);

        foreach ($andWhere as $column => $value) {
            $query->where($column, $value);
        }

        return $query->update(array_merge(['status' => $to], $extra));
    }

    /** 이력 기록 — 이력 테이블은 workflow_jobs에만 존재하므로 기본은 no-op (Spec §15) */
    protected function writeHistory(Model $model, string $from, string $to, string $actor, ?string $note): void
    {
        // no-op
    }

    protected function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(static::class.'::transition()은 호출부가 소유한 트랜잭션 안에서만 실행해야 한다');
        }
    }
}
