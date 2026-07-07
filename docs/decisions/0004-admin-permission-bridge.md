# ADR-0004: 관리자 권한은 users 테이블 브리지 컬럼(permissions/direct_permissions)으로 판정한다

- 상태: 채택 (2026-07-07)
- 관련 문서: Laravel Controller Service Spec §14 · Admin API Spec §3 · MVP Scope §2

## 문제 (문서-코드 간극)

- Controller Service Spec §14는 "권한 저장은 **기존 관리자 권한 테이블** 사용"을 전제하고,
  HIGH 권한(release_lock·worker.manage·force_publish·master.download)은
  `hasDirectPermission`(개별 부여)으로만 인정한다고 명시한다.
- 이 저장소(Laravel 스켈레톤)에는 기존 관리자 권한 시스템이 존재하지 않는다.
  DB Table Spec의 17개 워크플로우 테이블에도 권한 테이블은 없다(발명 금지 대상).

## 결정

1. `users` 테이블에 `permissions jsonb DEFAULT '[]'`, `direct_permissions jsonb DEFAULT '[]'`
   두 컬럼을 추가한다 (워크플로우 테이블 발명이 아닌, 스펙이 전제한 기존 시스템의 최소 브리지).
2. `User::hasPermission($p)` = permissions ∪ direct_permissions 포함 여부.
   `User::hasDirectPermission($p)` = **direct_permissions만** 검사 — HIGH 권한 전용.
3. Gate 정의는 `WorkflowPermissionService::registerGates()` 단일 지점:
   일반 권한 → hasPermission · HIGH 권한 → hasDirectPermission (역할 상속 불가 — Spec §14).
4. 실제 CMS의 권한 시스템(역할/부여 이력) 도입 시 이 브리지의 Gate 정의부만 교체한다.

## 근거

MVP Admin API의 권한 계층(라우트 can → FormRequest authorize → CommandService 재확인)과
403 테스트는 권한 저장소 없이 구현 불가. 문서의 의미론(HIGH = 개별 부여만)을 보존하는
최소 구현이며, 워크플로우 스키마(17테이블)에는 손대지 않는다.
