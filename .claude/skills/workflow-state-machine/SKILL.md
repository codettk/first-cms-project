---
name: workflow-state-machine
description: Frame CMS/MAM Workflow의 StateMachine 계층 구현 — 상태 전이(transition), CAS update, 전이 history 기록, transaction 소유권. 상태 변경 로직·전이 규칙·전이 테스트 관련 요청에 사용한다.
---

# Workflow State Machine

## 목적

State Machine Specification을 기준으로 StateMachine 클래스(Job/Content/WorkflowInstance/Worker/SearchIndex), 전이 규칙, CAS 기반 update, history 기록, transaction 소유권 규칙을 구현한다. 모든 상태 변경은 StateMachine을 통해서만 이뤄지도록 보장한다.

## 사용 시점

- StateMachine 클래스 신규 구현·수정 요청
- 상태 전이(transition) 규칙 추가·변경
- CAS(compare-and-swap) update, 전이 충돌 처리
- 전이 history(`workflow_job_status_histories` 등) 기록 구현
- 직접 status update 코드 제거/StateMachine 경유로 전환

## 필수 참고 문서

실제 문서 경로: `docs/workflow/_source/claude-design/`

공통 우선 확인: `HANDOFF_README.md`, `Revision Checklist.dc.html`, `MVP Scope Git Strategy.dc.html`, `WBS Milestone Plan.dc.html`, `Implementation Roadmap.dc.html`

작업별 필수:

- `docs/workflow/_source/claude-design/Revision Checklist.dc.html`
- `docs/workflow/_source/claude-design/State Machine Specification.dc.html`
- `docs/workflow/_source/claude-design/Laravel Controller Service Specification.dc.html`
- `docs/workflow/_source/claude-design/Queue Worker Specification.dc.html`

`.dc.html` 문서는 `.md`로 변환하지 않고 그대로 읽는다.

## 절대 규칙 (요약)

- 모든 status 변경은 StateMachine을 통한다 — Eloquent 직접 `update(['status' => ...])` 금지.
- StateMachine은 자체 transaction을 열지 않는다 — `assert(DB::transactionLevel() > 0)`로 호출자 transaction 안에서만 동작. 단독 전이는 TransitionRunner가 transaction을 소유한다.
- CAS update: `WHERE id = ? AND status = ?` — 충돌은 예외가 아니라 `TransitionResult::conflict` 값으로 반환한다.
- 전이 성공 시 history를 같은 transaction 안에서 기록한다.
- 문서의 전이 표(허용 전이 쌍)에 없는 전이는 거부한다.

## 절차

1. 기존 status update 코드 검색 (`Grep`으로 `->status =`, `update(['status'`, `'status' =>` 패턴 전수 조사)
2. 직접 status update 위험 확인 (StateMachine 우회 지점 목록화, 발견 시 보고)
3. StateMachine 클래스 설계 (AbstractStateMachine + 대상별 5종: Job/Content/WorkflowInstance/Worker/SearchIndex, 전이 표는 문서 기준)
4. transition 구현 (`canTransition` / `assertTransition` / `transition` — CAS + TransitionResult)
5. history 기록 구현 (전이 성공 시 from/to/reason/actor 기록)
6. Transaction 소유권 확인 (StateMachine 무소유·TransitionRunner 소유 — 호출 경로별 점검)
7. 허용/비허용 전이 테스트 작성 (문서 전이 표의 허용 쌍 전수 + 대표 비허용 쌍, CAS 충돌 케이스)
8. arch test 작성 (StateMachine 외부에서의 직접 status 변경 금지를 정적으로 검증)

## 출력 형식

```text
## StateMachine Result

- Documents read:
- Files created:
- Files modified:
- Transition rules implemented:
- Tests run:
- Remaining risks:
```
