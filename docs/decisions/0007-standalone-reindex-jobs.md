# ADR-0007: STALE 재색인 INDEX job은 인스턴스 무소속(단독)으로 생성한다

- 상태: 채택 (2026-07-09)
- 관련 문서: State Machine Specification §14 · DB Table Specification §4 (workflow_jobs)

## 문제

SM Spec §14는 STALE 감지 시 "재색인 INDEX job 생성(**또는 기존 인스턴스와 무관한
단독 job**)"을 정의한다. 그러나 `workflow_jobs`는 `UNIQUE(instance_id, job_type)`
제약이 있어, 이미 INDEX job이 SUCCESS로 존재하는 인스턴스에는 재색인 INDEX job을
추가할 수 없다 (DocPipelineTest에서 unique violation으로 검출).

## 결정

1. SM Spec §14가 명시한 두 번째 선택지 — **기존 인스턴스와 무관한 단독 job** — 를
   채택한다. `workflow_jobs.instance_id`를 nullable로 완화한다(FK는 유지).
   UNIQUE(instance_id, job_type)는 Postgres 의미상 NULL 행에 적용되지 않으므로
   재색인 job은 콘텐츠당 여러 번 생성 가능하되, Scheduler의 비종결 INDEX job
   중복 억제가 동시 1건을 보장한다.
2. Scheduler ①(WAITING→READY 승격)은 instance_id IS NULL인 job을 인스턴스 상태와
   무관하게 승격 대상으로 포함한다 — 단독 job은 의존성도 인스턴스 수명도 없다.
3. 재색인 대상 가드는 인스턴스가 아니라 **콘텐츠 상태(READY)** 로 판정한다 —
   처리 중(PROCESSING)이면 파이프라인 INDEX job이 곧 실행되고(비종결 중복 억제),
   FAILED/ARCHIVED/DELETED 콘텐츠는 수동 재색인 대상으로 남긴다.

## 근거

파이프라인 인스턴스는 콘텐츠 1회 수급의 실행 기록이고, 재색인은 수급과 무관한
운영 반복 작업이다. 인스턴스를 재사용하면 UNIQUE 제약·인스턴스 종결 의미가
깨지고, 재색인용 인스턴스를 새로 만들면 uq_instances_running(콘텐츠당 RUNNING
1건)과 충돌한다. 단독 job이 스펙 원문과 제약 모두에 부합하는 유일한 형태다.
