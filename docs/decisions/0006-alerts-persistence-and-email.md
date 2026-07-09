# ADR-0006: alerts 영속화 + HIGH 이벤트 이메일 발송을 구현한다 (ADR-0002 개정)

- 상태: 채택 (2026-07-09)
- 개정 대상: ADR-0002 (alerts 계약만 유지·구현 보류)
- 관련 문서: Admin API Specification §21 · Admin UI Wireframe Specification §13 · openapi.yaml `WorkflowAlertItem`

## 문제

ADR-0002는 DB Table Specification 17테이블에 alerts 테이블이 없어 영속화를 보류했다.
운영 게이트(체크리스트 §5)에서 알림센터·이메일 발송이 잔여 항목으로 남아 있었다.

## 결정

1. 테이블명 `workflow_alerts` — 스키마는 임의 발명이 아니라 **Admin API Spec §21 /
   openapi.yaml `WorkflowAlertItem` 계약 필드에서 도출**한다: `alert_id`→`id`,
   `severity`(HIGH/MED/LOW CHECK), `event_type`, `message`,
   `related_content_id`/`related_job_id`/`related_worker_id`(nullable FK),
   `action_link`, `created_at`, `acknowledged_at`, `acknowledged_by`(users FK)
   \+ ack 사유 `ack_note`(§21 요청 body `note`).
2. 이벤트 소스는 Scheduler 틱 — 필수 job 최종 실패(`REQUIRED_JOB_FAILED`)·Worker
   OFFLINE(`WORKER_OFFLINE`)부터 구현한다. Wireframe §13의 나머지 이벤트는 감지
   지점이 생길 때 동일한 `AlertService::raise()`로 추가한다.
3. 미확인(acknowledged_at IS NULL) 동일 이벤트·동일 대상 알림은 중복 생성하지
   않는다 — 틱마다 재감지되는 상태성 이벤트의 스팸 방지.
4. 이메일은 Wireframe §13의 이메일 채널 HIGH 이벤트(필수 실패·Storage I/O·Master
   누락)만 발송한다. 수신자는 `WORKFLOW_ALERT_MAIL_TO`(콤마 구분) — 빈 값이면
   발송 생략. 발송 실패는 경고 로그만 남기고 Scheduler를 중단시키지 않는다.
5. `GET /alerts`·`POST /alerts/{id}/ack` 라우트를 계약 그대로 등록한다
   (ack는 멱등 + audit.write). 대시보드 `banners`의 실시간 파생은 유지한다 —
   배너(즉시 상태)와 알림센터(누적 이력)는 표시 계층이 다르다.

## 근거

계약(Admin API Spec §21)이 필드를 이미 완전하게 정의하므로 "테이블/컬럼 임의
발명 금지" 원칙에 저촉되지 않는다. DB Table Specification v1.2 개정 시 본 ADR을
원문으로 사용한다.
