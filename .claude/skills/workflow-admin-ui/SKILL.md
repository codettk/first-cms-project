---
name: workflow-admin-ui
description: Frame CMS/MAM Workflow의 Admin UI 구현 — Design System 기반 화면·컴포넌트, available_actions 기반 버튼 제어, HIGH 위험 버튼 기본 미노출. 관리자 화면·컴포넌트·와이어프레임 구현 요청에 사용한다.
---

# Workflow Admin UI

## 목적

Admin UI Wireframe Specification과 Design System을 기준으로 관리자 화면과 컴포넌트를 구현한다. 버튼 노출은 서버가 계산한 `available_actions`만 근거로 하며, 클라이언트에서 상태를 자체 판정해 버튼을 만들지 않는다.

## 사용 시점

- Admin 화면(대시보드, 콘텐츠 목록/상세, Job 목록/상세, Worker 목록 등) 구현
- Design System 토큰·컴포넌트(Button, DataTable, Badge, Modal 등) 적용
- available_actions 기반 액션 버튼 제어, HIGH 위험 버튼 처리
- 레이아웃(AppShell, Sidebar, Topbar), 라우팅 작업

## 필수 참고 문서

실제 문서 경로: `docs/workflow/_source/claude-design/`

공통 우선 확인: `HANDOFF_README.md`, `Revision Checklist.dc.html`, `MVP Scope Git Strategy.dc.html`, `WBS Milestone Plan.dc.html`, `Implementation Roadmap.dc.html`

작업별 필수:

- `docs/workflow/_source/claude-design/Revision Checklist.dc.html`
- `docs/workflow/_source/claude-design/Admin UI Wireframe Specification.dc.html`
- `docs/workflow/_source/claude-design/Design System.dc.html`
- `docs/workflow/_source/claude-design/Frame CMS.dc.html`
- `docs/workflow/_source/claude-design/Frame Workspace.dc.html`
- `docs/workflow/_source/claude-design/Admin API Specification.dc.html`
- `docs/workflow/_source/claude-design/OpenAPI Specification.dc.html`
- `docs/workflow/_source/claude-design/MVP Scope Git Strategy.dc.html`

개별 컴포넌트 스펙도 같은 경로에 있다 (`Button.dc.html`, `DataTable.dc.html`, `Badge.dc.html`, `Modal.dc.html`, `AppShell.dc.html`, `Sidebar.dc.html`, `Topbar.dc.html` 등) — 구현 대상 컴포넌트의 문서를 함께 읽는다.

`.dc.html` 문서는 `.md`로 변환하지 않고 그대로 읽는다.

## 절대 규칙 (요약)

- 액션 버튼은 API 응답의 `available_actions`에 있는 것만 렌더링한다 — 클라이언트에서 상태로 버튼 노출을 판정하지 않는다.
- HIGH 위험 버튼(release-lock, worker 관리 등)은 기본 UI에서 미노출 (API는 존재하되 화면에서는 숨김 — MVP Scope 기준).
- Master 파일 direct path를 화면·링크·다운로드 URL 어디에도 노출하지 않는다.
- Design System 토큰을 사용하고 임의 색상·간격 하드코딩을 피한다.

## 절차

1. 기존 프론트엔드 구조 확인 (`resources/`, `package.json`, 빌드 설정 — 없으면 문서 기준 스택 제안 후 보고)
2. 라우팅, 레이아웃, 컴포넌트 패턴 확인 (기존 convention 우선)
3. Design System token 확인 (`Design System.dc.html` — 색상/타이포/간격/상태 토큰)
4. Admin UI Wireframe 확인 (구현 대상 화면의 와이어프레임 섹션 정독)
5. API 응답 구조 확인 (`openapi.yaml` + Admin API Spec — 화면이 소비할 필드 목록화)
6. available_actions 기반 버튼 노출 적용
7. HIGH 위험 버튼 기본 미노출 처리
8. Master direct path 노출 방지 검증 (렌더링 결과·링크 점검)
9. 화면별 컴포넌트 구현 (와이어프레임 단위로 완성, 컴포넌트 스펙 문서 준수)

## 출력 형식

```text
## Admin UI Result

- Documents read:
- Screens implemented:
- Components created:
- Files modified:
- Tests run:
- Remaining issues:
```
