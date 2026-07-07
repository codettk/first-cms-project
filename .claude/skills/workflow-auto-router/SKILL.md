---
name: workflow-auto-router
description: Frame CMS/MAM Workflow 관련 요청을 분석해 적절한 workflow skill과 agent를 선택한다. 요청이 DB·StateMachine·Worker·Admin API·Admin UI·리뷰 중 어느 영역인지 불명확하거나, 작업 착수 전 라우팅이 필요할 때 사용한다.
---

# Workflow Auto Router

## 목적

사용자 요청을 분석하여 Frame CMS/MAM Workflow 구현 작업에 적합한 skill과 agent 조합을 선택하고, 읽어야 할 문서와 점검할 파일, 리스크를 정리해 라우팅 결과를 보고한다. 이 skill 자체는 구현을 수행하지 않는다 — 분류와 위임 준비만 담당한다.

## 사용 시점

- Frame CMS/MAM Workflow 관련 요청이 들어왔으나 어느 영역(DB/StateMachine/Worker/API/UI/리뷰)인지 판단이 필요할 때
- 여러 영역에 걸친 요청을 단계별 skill 실행 순서로 분해해야 할 때
- 구현 착수 전에 필요한 문서·파일·리스크를 먼저 정리하고 싶을 때

## 공통 문서 위치

실제 문서 경로: `docs/workflow/_source/claude-design/`

작업 전 아래 문서를 우선 확인한다:

- `docs/workflow/_source/claude-design/HANDOFF_README.md`
- `docs/workflow/_source/claude-design/Revision Checklist.dc.html`
- `docs/workflow/_source/claude-design/MVP Scope Git Strategy.dc.html`
- `docs/workflow/_source/claude-design/WBS Milestone Plan.dc.html`
- `docs/workflow/_source/claude-design/Implementation Roadmap.dc.html`

`.dc.html` 문서는 절대 `.md`로 변환하지 않고 그대로 읽는다.

## 분류 기준

| 요청 키워드 | 선택 skill | 선택 agent |
|---|---|---|
| DB / migration / seed / model / enum / CHECK / partial index / trigger | workflow-db-foundation | workflow-db-architect |
| StateMachine / transition / CAS / history / 상태 전이 | workflow-state-machine | workflow-state-machine-engineer |
| Scheduler / Queue / Worker / Claim / Lock / Heartbeat / Fencing / Storage / Rendition / Profile / Handler | workflow-worker | workflow-worker-engineer |
| Admin API / OpenAPI / Controller / FormRequest / Service / Resource / AuditLogger | workflow-admin-api | workflow-admin-api-engineer |
| Admin UI / Design System / Component / Screen / 화면 / available_actions | workflow-admin-ui | workflow-admin-ui-engineer |
| Test / Review / Validation / Runbook / 검토 / 정합성 | workflow-review | workflow-qa-reviewer |

## 절차

1. 요청 문장에서 도메인 키워드를 추출한다.
2. 위 분류 기준 표에 대조하여 skill + agent를 선택한다. 복수 영역에 걸치면 의존 순서(DB → StateMachine → Worker → Admin API → Admin UI → Review)대로 나열한다.
3. 선택된 skill의 필수 참고 문서 목록을 정리한다 (공통 문서 5종 + 해당 skill의 필수 문서).
4. 점검할 기존 코드 파일을 식별한다 (`app/`, `database/`, `routes/`, `tests/`, `openapi.yaml` 등).
5. 리스크를 평가한다 — 절대 규칙 위반 가능성(직접 status update, variant_key 누락, Master 노출 등), 문서 충돌 여부, MVP 범위 이탈 여부.
6. 아래 출력 형식으로 라우팅 결과를 보고하고, 다음 단계로 선택된 skill 실행을 제안한다.

## 출력 형식

```text
## Workflow Routing Result

- Request:
- Selected skill:
- Selected agent:
- Documents to read:
- Files to inspect:
- Risk:
- Next step:
```
