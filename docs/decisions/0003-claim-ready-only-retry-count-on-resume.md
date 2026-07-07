# ADR-0003: Claim은 READY만 소비하고 retry_count는 RETRY→READY 재개 시 증가한다

- 상태: 채택 (2026-07-07)
- 관련 문서: State Machine Spec §5·§9 · Queue Worker Spec §5·§8·§11 · Worker Agent Spec §6

## 문제 (문서 충돌)

- Queue Worker Spec §5·Worker Agent Spec §6의 Claim SQL은 `status IN ('READY','RETRY')` 행을
  직접 `RUNNING`으로 전환하고, 이때 `CASE WHEN status='RETRY' THEN retry_count+1`로 증가시킨다.
- State Machine Spec §5 전이표는 **RETRY → RUNNING을 금지**한다 ("✕ — READY 경유").
  Sprint 1에서 배포된 `workflow_job_allowed_transitions` seed(18쌍)와 `check_job_transition`
  trigger도 동일하게 차단한다 — 스펙의 Claim SQL은 RETRY 행에서 trigger 예외를 일으킨다.
- 한편 Queue Worker Spec §8-④는 Scheduler가 RETRY→READY를 전환한다고 명시하는데,
  이 경로를 타면 Claim 시점에는 READY라 retry_count가 영원히 증가하지 않는다(무한 재시도 버그).

## 결정

1. **Claim은 READY 상태만 소비한다** — `WHERE status = 'READY'` (FOR UPDATE SKIP LOCKED · priority DESC, created_at ASC 유지).
2. **retry_count 증가는 Scheduler의 RETRY 재개 단계(§8-④)에서 수행한다**:
   `next_attempt_at` 도래 시 `retry_count < max_retry`면 RETRY→READY + `retry_count+1`,
   아니면 RETRY→FAILED(소진 확정 — §9 "RETRY 재개 단계에서 검사"와 일치).
3. 백오프는 RUNNING→RETRY 전이 시점에 `base × 2^retry_count`(당시 값)로 계산해 `next_attempt_at`에 기록한다.

## 근거

- State Machine Spec이 상태 전이의 전담 문서이고, Revision Checklist P0-4가
  "모든 상태 변경은 StateMachine 경유"를 확정 — 전이표(RETRY→RUNNING ✕)가 우선한다.
- 이 설계로 총 시도 횟수(1 + max_retry) · 소진 검사 위치(재개 단계) · 백오프 산식이
  모든 문서 수치와 일치한다. 검증: 1차 실패(retry_count=0)→backoff 1분→재개(1)→2차 실패(1)→2분→
  재개(2)→3차 실패(2)→4분→재개(3)→4차 실패(3)→재개 검사에서 3≥max_retry(3)→FAILED.

## 영향

- `JobClaimService.tryClaim()`: READY 전용, retry_count 미변경.
- `WorkflowSchedulerService` RETRY 재개 단계: 소진 판정 + retry_count 증가 담당.
- Worker Agent Spec §6 예시 코드의 CASE 증가분은 채택하지 않음.
