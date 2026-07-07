# Handoff: 콘텐츠 관리 시스템 (CMS) 디자인 & 스펙 문서

## Claude Code에게

이 폴더의 파일들은 **HTML로 작성된 디자인 레퍼런스 및 기술 스펙 문서**입니다. 프로덕션 코드가 아니라, 의도된 UI/동작/아키텍처를 보여주는 참고 자료입니다. 실제 구현은 대상 코드베이스의 환경(Laravel + 프론트엔드 프레임워크)과 기존 패턴/라이브러리를 따라 재구현해야 합니다.

`.dc.html` 파일은 브라우저에서 직접 열 수 있는 단일 파일 디자인 컴포넌트입니다. 내부의 `<x-dc>` 템플릿(마크업 + 인라인 스타일)과 `data-dc-script` 로직을 참고하세요. `support.js`, `ds-icons.js`, `doc-page.js`는 런타임 헬퍼입니다.

## 파일 분류

### 기술 스펙 문서 (구현 기준 문서)
- Admin API Specification.dc.html
- OpenAPI Specification.dc.html
- DB Table Specification.dc.html
- Migration Seed Specification.dc.html
- Laravel Controller Service Specification.dc.html
- Queue Worker Specification.dc.html
- Worker Agent Specification.dc.html
- Job Type Definition.dc.html
- Transcode Profile Specification.dc.html
- State Machine Specification.dc.html
- Media Workflow Diagram.dc.html (+ standalone .html)

### 기획/프로세스 문서
- Admin UI Wireframe Specification.dc.html
- Implementation Roadmap.dc.html
- WBS Milestone Plan.dc.html
- MVP Scope Git Strategy.dc.html
- Incident Response Runbook.dc.html
- Revision Checklist.dc.html

### 디자인 시스템 & UI 컴포넌트 (하이파이 디자인 레퍼런스)
- Design System.dc.html — 토큰/스타일 총람. 색상·타이포·간격 값은 여기서 확인
- 레이아웃: AppShell, Sidebar, Topbar, Breadcrumb
- 컴포넌트: Accordion, AssetCard, Avatar, Badge, Button, Checkbox, Chip, DataTable, Drawer, Input, MetaField, Modal, MultiSelect, Pagination, Rating, Select, StatCard, Tabs, Textarea, Toast, Toggle
- 전체 화면 목업: Frame CMS.dc.html, Frame Workspace.dc.html

## 구현 가이드
1. 스펙 문서(API/DB/State Machine)를 우선 읽고 백엔드 구조를 잡을 것
2. UI는 Design System.dc.html의 토큰을 기준으로, Frame CMS / Frame Workspace 화면과 컴포넌트 파일들을 픽셀 참조하여 재구현
3. 각 .dc.html의 인라인 스타일에서 정확한 색상/타이포/간격 값을 추출할 것
