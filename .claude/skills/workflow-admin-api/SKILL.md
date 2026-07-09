---
name: workflow-admin-api
description: Frame CMS/MAM Workflow의 Admin API 계층 구현 — Route, Controller, FormRequest, QueryService, CommandService, Resource, AuditLogger, Exception 매핑, OpenAPI 계약 준수. Admin API 엔드포인트·계약 관련 요청에 사용한다.
---

# Workflow Admin API

## 목적

Admin API Specification, Laravel Controller Service Specification, openapi.yaml 계약을 기준으로 Admin API 계층(Routes → Controller → FormRequest → Query/CommandService → StateMachine → Resource)을 구현한다. 응답 형태는 OpenAPI 계약과 정확히 일치해야 한다.

## 사용 시점

- Admin API 라우트/컨트롤러/서비스 신규 구현·수정
- FormRequest 검증 규칙, Resource 응답 형태 작업
- AuditLogger, 사유(reason) 필수 액션, 예외→HTTP 매핑
- openapi.yaml 계약 변경·검증, 계약 테스트

## 필수 참고 문서

실제 문서 경로: `docs/workflow/_source/claude-design/`

공통 우선 확인: `HANDOFF_README.md`, `Revision Checklist.dc.html`, `MVP Scope Git Strategy.dc.html`, `WBS Milestone Plan.dc.html`, `Implementation Roadmap.dc.html`

작업별 필수:

- `docs/workflow/_source/claude-design/Revision Checklist.dc.html`
- `docs/workflow/_source/claude-design/Admin API Specification.dc.html`
- `docs/workflow/_source/claude-design/Laravel Controller Service Specification.dc.html`
- `docs/workflow/_source/claude-design/OpenAPI Specification.dc.html`
- `docs/workflow/_source/claude-design/MVP Scope Git Strategy.dc.html`

프로젝트 계약 파일: 루트 `openapi.yaml` (spectral 0 errors·0 warnings 유지, `$ref`는 전체 경로 사용)

`.dc.html` 문서는 `.md`로 변환하지 않고 그대로 읽는다.

## 절대 규칙 (요약)

- `jobs/retry-batch` 라우트는 `jobs/{job}`보다 먼저 등록한다.
- GET 요청은 audit log를 쓰지 않는다. 상태 변경 액션만 AuditLogger 기록.
- REASON_REQUIRED 액션(`content.reprocess`, `content.cancel`, `job.cancel`, `job.release_lock`, `worker.disable`, `worker.clear_error`, `content.master_download`)은 reason 필수.
- 상태 변경은 CommandService → StateMachine 경유. QueryService는 읽기 전용.
- `available_actions`는 서버에서 계산해 응답에 포함한다 (AvailableActionService 매트릭스).
- HIGH 권한은 `hasDirectPermission`으로만 판정. Master 경로는 응답에서 maskPath 처리.
- Alerts API는 MVP 구현 보류 (ADR-0002 — 계약만 유지, 라우트 미등록).
- 예외→HTTP 매핑은 문서 기준 (409 `INVALID_STATE_TRANSITION` 등).

## 절차

1. 기존 `routes/admin.php` 구조 확인 (없으면 문서 기준 신설 계획 보고)
2. `jobs/retry-batch` 라우트 순서 확인 (`jobs/{job}`보다 앞)
3. Route 작성 (문서의 라우트 목록·`->can()` 권한 준수)
4. FormRequest 작성 (문서의 검증 규칙, REASON_REQUIRED 반영)
5. QueryService 작성 (읽기 전용, 필터·페이지네이션 계약 준수)
6. CommandService 작성 (transaction 소유, StateMachine 호출, AuditLogger 기록)
7. Resource 작성 (openapi.yaml 스키마와 필드 1:1, available_actions 포함)
8. AuditLogger 작성 (maskPath 헬퍼, GET 미기록 보장)
9. Exception mapping 작성 (도메인 예외 → HTTP 상태·에러 코드)
10. OpenAPI 계약 테스트 확인 (응답-계약 일치 테스트 실행, `npx @stoplight/spectral-cli lint openapi.yaml --fail-severity=warn` 통과)

## 출력 형식

```text
## Admin API Result

- Documents read:
- Routes added:
- Files created:
- Files modified:
- Contract checks:
- Tests run:
- Remaining issues:
```
