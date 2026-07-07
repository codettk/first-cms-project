---
name: workflow-review
description: Frame CMS/MAM Workflow 구현 결과를 설계 문서 기준으로 검토한다 — schema/enum/StateMachine/Worker/API/UI 정합성 확인, 금지 패턴 검색, 테스트 실행, pass/fail 보고. 구현 완료 후 검증·리뷰·정합성 점검 요청에 사용한다.
---

# Workflow Review

## 목적

구현 결과물을 Claude Design 문서(Source of Truth)와 대조해 정합성을 검증하고, 절대 규칙 위반(금지 패턴)을 탐지하며, 테스트를 실행해 pass/fail을 보고한다. 이 skill은 검토와 보고만 수행한다 — 수정은 지적 사항을 근거로 별도 skill이 담당한다.

## 사용 시점

- Sprint/커밋 단위 구현 완료 후 검증 요청
- PR 전 정합성 점검, 금지 패턴 감사
- 문서-코드 불일치 의심 시 확인
- Runbook 기준 운영 시나리오 점검

## 필수 참고 문서

실제 문서 경로: `docs/workflow/_source/claude-design/`

공통 우선 확인: `HANDOFF_README.md`, `Revision Checklist.dc.html`, `MVP Scope Git Strategy.dc.html`, `WBS Milestone Plan.dc.html`, `Implementation Roadmap.dc.html`

작업별 필수:

- `docs/workflow/_source/claude-design/Revision Checklist.dc.html`
- `docs/workflow/_source/claude-design/WBS Milestone Plan.dc.html`
- `docs/workflow/_source/claude-design/MVP Scope Git Strategy.dc.html`
- `docs/workflow/_source/claude-design/Incident Response Runbook.dc.html`
- `docs/workflow/_source/claude-design/OpenAPI Specification.dc.html`

검토 대상 영역에 따라 해당 스펙 문서를 추가로 읽는다 (DB Table Specification, State Machine Specification, Queue Worker Specification, Admin API Specification, Admin UI Wireframe Specification 등).

문서 충돌 의사결정 기록: `docs/decisions/` (ADR — 검토 시 ADR로 확정된 결정은 위반으로 판정하지 않는다)

`.dc.html` 문서는 `.md`로 변환하지 않고 그대로 읽는다.

## 검토 절차

1. 변경 파일 확인 (`git diff`/`git status`/최근 커밋 — 검토 범위 확정)
2. 관련 문서 선택 (변경 영역별 스펙 문서 매핑)
3. schema / enum / StateMachine / Worker / API / UI 정합성 확인 (문서 정의와 코드 1:1 대조 — 테이블·컬럼·전이 표·claim SQL·라우트·응답 스키마·화면 버튼)
4. 금지 패턴 검색 (아래 목록을 Grep으로 전수 검색)
5. 테스트 실행 또는 제안 (`php artisan test`, spectral lint — 실행 불가 환경이면 실행 계획 제안)
6. pass/fail 보고 (항목별 판정 + 수정 필요 사항 + 리스크 등급)

## 금지 패턴

| 패턴 | 탐지 방법 |
|---|---|
| 직접 status update (StateMachine 우회) | `update(['status'`, `->status =` 후 `save()` 검색 — StateMachine/TransitionRunner 외부 사용 |
| variant_key 누락 | rendition 관련 unique index·upsert에 variant_key 부재 |
| workflow_jobs에 progress 저장 | workflow_jobs migration/모델에 progress 컬럼·기록 코드 |
| COMMON이 contents.media_type에 추가됨 | contents CHECK 제약·enum에 COMMON 포함 여부 |
| Master direct path 노출 | 응답·UI에 master 경로 원문 노출 (maskPath 미적용) |
| GET audit write | GET 핸들러·QueryService에서 AuditLogger 호출 |

## 출력 형식

```text
## Workflow Review Result

- Changed files:
- Documents checked:
- Passed:
- Failed:
- Required fixes:
- Risk level:
- Recommended next task:
```
