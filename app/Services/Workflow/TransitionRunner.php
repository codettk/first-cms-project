<?php

namespace App\Services\Workflow;

use App\Services\Workflow\StateMachine\AbstractStateMachine;
use App\Services\Workflow\StateMachine\TransitionResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 단독 전이 wrapper — Controller Service Spec §6.
 * StateMachine은 트랜잭션을 열지 않으므로, 다른 작업과 묶이지 않는 단독 전이는
 * 이 wrapper가 최상위 트랜잭션을 소유한다.
 */
class TransitionRunner
{
    /**
     * @param array<string, mixed> $extra
     */
    public function run(AbstractStateMachine $machine, Model $model, string $to, string $actor, ?string $note = null, array $extra = []): TransitionResult
    {
        return DB::transaction(
            fn (): TransitionResult => $machine->transition($model, $to, $actor, $note, $extra)
        );
    }
}
