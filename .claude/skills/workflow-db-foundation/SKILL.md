---
name: workflow-db-foundation
description: Frame CMS/MAM Workflow의 DB 기반 작업 — migration, seed, enum, model, factory, PostgreSQL CHECK 제약, partial index, trigger, schema 테스트. 테이블·컬럼·시드 데이터·모델 캐스트 관련 요청에 사용한다.
---

# Workflow DB Foundation

## 목적

DB Table Specification과 Migration Seed Specification을 기준으로 스키마(migration, CHECK, partial index, trigger), 시드, enum, model/factory, DB 테스트를 구현한다. 문서에 없는 테이블·컬럼명은 발명하지 않으며, 문서-코드 충돌은 보고 후 ADR(`docs/decisions/`)로 결정한다.

## 사용 시점

- 테이블 추가/변경, migration 작성, 시드 데이터 작성 요청
- enum(PHP backed enum) 추가·수정
- PostgreSQL CHECK 제약, partial unique index, trigger 작업
- Eloquent model, cast, relation, factory 작업
- 스키마/시드 정합성 테스트 작성

## 필수 참고 문서

실제 문서 경로: `docs/workflow/_source/claude-design/`

공통 우선 확인: `HANDOFF_README.md`, `Revision Checklist.dc.html`, `MVP Scope Git Strategy.dc.html`, `WBS Milestone Plan.dc.html`, `Implementation Roadmap.dc.html`

작업별 필수:

- `docs/workflow/_source/claude-design/Revision Checklist.dc.html`
- `docs/workflow/_source/claude-design/DB Table Specification.dc.html`
- `docs/workflow/_source/claude-design/Migration Seed Specification.dc.html`
- `docs/workflow/_source/claude-design/Transcode Profile Specification.dc.html`
- `docs/workflow/_source/claude-design/MVP Scope Git Strategy.dc.html`

`.dc.html` 문서는 `.md`로 변환하지 않고 그대로 읽는다.

## 절대 규칙 (요약)

- `contents.media_type`에 `COMMON`을 추가하지 않는다 (COMMON은 job_type 분류에만 존재).
- rendition unique index에는 `variant_key`가 반드시 포함된다.
- progress는 `workflow_job_progresses` 테이블에만 저장한다 — `workflow_jobs`에 progress 컬럼을 만들지 않는다.
- 문서에 없는 테이블/컬럼명을 발명하지 않는다. 충돌은 ADR로 기록한다 (예: ADR-0001 TC profile code).

## 절차

1. 기존 Laravel 프로젝트 구조 확인 (`app/Models`, `app/Enums`, `database/migrations`, `database/seeders`, `database/factories`)
2. 기존 migration/model/seed convention 확인 (네이밍, 타임스탬프 순서, seeder 등록 방식)
3. 대상 파일 보고 (생성/수정 예정 파일 목록을 먼저 제시)
4. enum 생성 (`app/Enums/` — backed enum, 문서의 값 목록과 1:1)
5. migration 생성 (문서의 테이블·컬럼·인덱스 정의 준수)
6. CHECK / partial index / trigger 작성 (raw SQL은 `DB::statement`, pgsql 전용임을 명시)
7. seed 작성 (멱등성 보장 — 재실행 시 중복 없음)
8. model / factory 생성 (cast, relation, `$fillable`/`$guarded` convention 준수)
9. DB 테스트 작성 (`tests/Feature/` — RefreshDatabase + `$this->seed()`, 스키마/CHECK/trigger/시드 검증)
10. `php artisan migrate:fresh --seed` 실행 (성공 및 시드 멱등성 확인 — `db:seed` 재실행 포함)
11. 관련 테스트 실행 (`php artisan test --filter=...`)

## 출력 형식

```text
## DB Foundation Result

- Documents read:
- Files created:
- Files modified:
- Schema decisions:
- Commands run:
- Test results:
- Remaining issues:
- Next task:
```
