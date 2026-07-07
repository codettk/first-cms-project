# ADR-0002: Alerts API는 계약만 유지하고 MVP 구현에서 보류한다

- 상태: 채택 (2026-07-07)
- 관련 문서: Admin API Specification §6·§21 · DB Table Specification(17테이블) · MVP Scope & Git Strategy §1

## 문제 (문서 충돌)

- Admin API Spec §21은 `GET /alerts`, `POST /alerts/{id}/ack`를 정의하고 `alert_id`, `acknowledged_at/by` 등 **영속 저장을 전제**로 한다.
- DB Table Specification의 17개 워크플로우 테이블에는 alerts 테이블이 없다 (Migration Seed Spec에도 없음).
- MVP Scope §1의 "MVP Admin API 포함" 목록에 Alerts API는 포함되어 있지 않다.

## 결정

1. openapi.yaml의 alerts 경로·스키마는 계약 문서로 유지한다(전체 계약 = 17개 엔드포인트).
2. MVP 구현에서는 alerts 엔드포인트를 **구현 보류**한다 — 라우트 미등록.
3. 대시보드 `banners`는 저장 없이 실시간 파생(OFFLINE Worker·필수 job FAILED 집계)으로 구현한다 — Admin API Spec §6 응답 형태 충족.
4. alerts 영속화(테이블 신설)는 DB 스펙 개정(문서 v1.2) 후 진행한다 — 테이블/컬럼명 임의 발명 금지 원칙.

## 근거

MVP 범위 문서가 Source of Truth 2순위이며 Alerts를 포함하지 않는다. 테이블이 스펙에 없는 상태에서 임의 스키마로 구현하면 절대 규칙(테이블명 발명 금지)에 위배된다.
