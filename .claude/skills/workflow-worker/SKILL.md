---
name: workflow-worker
description: Frame CMS/MAM Workflow의 실행 계층 구현 — Scheduler, Worker, Job Claim, Lock/Lease, Heartbeat, Fencing, Storage, Rendition, Transcode Profile, Job Handler. 큐 처리·미디어 처리·워커 헬스 관련 요청에 사용한다.
---

# Workflow Worker

## 목적

Queue Worker Specification과 Worker Agent Specification을 기준으로 Scheduler tick, Worker 루프, FOR UPDATE SKIP LOCKED claim, lock lease/heartbeat/fencing, Storage 계층(Master 보호), rendition upsert, Transcode Profile 처리, MVP 범위 Handler를 구현한다.

## 사용 시점

- Scheduler(tick, READY 전환, SKIPPED 전파, PUBLISH 판정, 타임아웃, Lock 회수, Worker 헬스) 구현
- Worker 루프, Job claim, retry/backoff 구현
- Lock/Lease, Heartbeat, Fencing 로직
- Storage 추상화, Master/Proxy 저장 경로, Rendition 생성·upsert
- Transcode Profile 조회·컴파일, Job Handler(MVP 범위) 구현

## 필수 참고 문서

실제 문서 경로: `docs/workflow/_source/claude-design/`

공통 우선 확인: `HANDOFF_README.md`, `Revision Checklist.dc.html`, `MVP Scope Git Strategy.dc.html`, `WBS Milestone Plan.dc.html`, `Implementation Roadmap.dc.html`

작업별 필수:

- `docs/workflow/_source/claude-design/Revision Checklist.dc.html`
- `docs/workflow/_source/claude-design/Queue Worker Specification.dc.html`
- `docs/workflow/_source/claude-design/Worker Agent Specification.dc.html`
- `docs/workflow/_source/claude-design/Job Type Definition.dc.html`
- `docs/workflow/_source/claude-design/Transcode Profile Specification.dc.html`
- `docs/workflow/_source/claude-design/Media Workflow Diagram.dc.html`
- `docs/workflow/_source/claude-design/MVP Scope Git Strategy.dc.html`

`.dc.html` 문서는 `.md`로 변환하지 않고 그대로 읽는다.

## 절대 규칙 (요약)

- Claim은 `FOR UPDATE SKIP LOCKED`, 정렬은 `priority DESC, created_at ASC` — 문서의 claim SQL을 그대로 따른다 (`status IN ('READY','RETRY') AND next_attempt_at <= now() AND job_type = ANY(...) ... LIMIT 1`).
- progress는 `workflow_job_progresses`에만 기록한다.
- rendition upsert는 `variant_key` 기반 unique 충돌 처리로 수행한다.
- Master 파일은 절대 노출·변경하지 않는다 — 읽기 원본으로만 사용, 산출물은 rendition 경로에 기록.
- 모든 상태 변경은 StateMachine 경유. lease fencing은 `WHERE worker_id = :me` 조건으로 수행.
- Handler는 MVP 범위 내에서만 구현한다 (MVP Scope 문서 기준 — 범위 밖 Handler는 stub/보류).

## 절차

1. 작업 유형 확인: Scheduler / Worker / Storage / Rendition / Profile / Handler 중 어디에 해당하는지 분류
2. 관련 기존 코드 확인 (`app/` 하위 서비스·커맨드, config, 기존 테스트)
3. 구현 전 계획 보고 (대상 파일·클래스·테스트 계획 제시)
4. Claim 구현 시 `FOR UPDATE SKIP LOCKED` 적용 (문서 SQL-1 준수, RETRY→RUNNING claim 시 retry_count 증가)
5. `priority DESC, created_at ASC` 정렬 적용
6. `workflow_job_progresses` 사용 (진행률·단계 기록 — workflow_jobs에 저장 금지)
7. `variant_key` 기반 rendition upsert 적용
8. Master Storage 보호 확인 (Master 경로 노출·쓰기 없음 검증)
9. MVP Handler 범위 내 구현 (범위 확인 후 초과분은 보고)
10. 동시성/lease/fencing 테스트 작성 (동시 claim 시 중복 없음, lease 만료 회수, fencing으로 구 worker 쓰기 차단)

## 출력 형식

```text
## Worker Workflow Result

- Task type:
- Documents read:
- Files created:
- Files modified:
- Commands run:
- Test results:
- Remaining issues:
```
