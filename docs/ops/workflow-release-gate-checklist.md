# Workflow 운영 릴리즈 게이트 체크리스트 (M5)

WBS Milestone Plan §M5 · Implementation Roadmap Phase 8 · Incident Response Runbook §3/§20 기준.
`v1.0-mvp` 태깅 전 아래 항목을 전부 통과해야 한다. 각 항목은 자동화 근거(테스트 파일)와
수동 리허설 절차를 함께 기재한다.

## 1. CI 게이트 5종 (머지 차단 기준)

| # | 게이트 | 자동화 근거 | 상태 |
|---|--------|------------|------|
| 1 | 단위·기능 테스트 전건 통과 | `php artisan test` (GitHub Actions) | 자동 |
| 2 | 상태 전이 전수 테스트 (enum ↔ seed ↔ trigger ↔ allowed_transitions 일치) | `tests/Feature/Database/SeedTest.php` · `TriggerTest.php` · `tests/Feature/StateMachine/*` | 자동 |
| 3 | arch 테스트 — 직접 status update 금지·StateMachine 우회 금지 | `tests/Feature/StateMachine/JobStateMachineTest.php` (금지 전이 전수) · DB trigger 2차 방어 | 자동 |
| 4 | OpenAPI 계약 테스트 + spectral lint | `tests/Feature/Admin/OpenApiContractTest.php` · `npx @stoplight/spectral-cli lint openapi.yaml --fail-severity=warn` | 자동 |
| 5 | 동시성 테스트 (claim·lease·fencing) | `tests/Feature/Queue/ClaimConcurrencyTest.php` · `SchedulerTest.php` | 자동 |

## 2. Runbook 장애 리허설 7종

| # | 리허설 | 자동화 근거 | 실 환경 리허설 절차 |
|---|--------|------------|---------------------|
| 1 | Worker kill 복구 | `tests/Feature/E2E/RunbookScenarioTest.php::test_worker_crash_recovery_end_to_end` | TC 실행 중 Worker 프로세스 kill → 90초 내 OFFLINE 판정·lock 회수 → 다른 Worker 재실행 확인 (Runbook §6-1) |
| 2 | Storage 차단 복구 | `tests/Feature/E2E/RunbookGateScenarioTest.php::test_storage_outage_retries_then_recovers_automatically` | zone mount 차단 → STORAGE_IO RETRY 확인 → mount 복구 → 자동 SUCCESS (Runbook §6-10) |
| 3 | Scheduler 정지 복구 | `tests/Feature/E2E/RunbookScenarioTest.php::test_scheduler_restart_recovers_orphan_running_jobs` | Scheduler 정지 → 재기동 첫 틱만으로 고아 RUNNING 회수 확인 (Runbook §6-21) |
| 4 | 필수 job 실패 판정/알림 | `tests/Feature/E2E/RunbookGateScenarioTest.php::test_required_failure_fails_content_and_raises_dashboard_banner` | 필수 job 최종 실패 → SKIPPED 전파·contents FAILED·대시보드 HIGH 배너 노출 확인 (Runbook §3) |
| 5 | Lock 타임아웃 회수 | `tests/Feature/Queue/SchedulerTest.php::test_timeout_reclassifies_to_retry_with_backoff_and_reclaims_lock` · `test_expired_lock_is_reclaimed_and_late_result_fenced` | timeout_sec 초과 job이 TIMEOUT→RETRY/FAILED로 자동 처리·늦은 결과 fencing 폐기 확인 (Runbook §6-6/22) |
| 6 | INDEX 실패 백오프/재시도 | `tests/Feature/E2E/RunbookGateScenarioTest.php::test_search_engine_outage_backs_off_then_recovers_automatically` | 검색엔진 중단 → INDEX_UNAVAILABLE 백오프(base 30초) → 엔진 복구 후 자동 SUCCESS·INDEXED 회복 (Runbook §6-15) |
| 7 | 콘텐츠 취소 정리 | `tests/Feature/Admin/AdminCommandApiTest.php::test_content_cancel_cancels_pipeline` · `tests/Feature/Queue/SchedulerTest.php::test_unresponsive_cancel_is_forced_by_scheduler` | 처리 중 콘텐츠 취소 → 비종결 job CANCELED·RUNNING cancel_requested·30초 무반응 강제 전이 확인 (Runbook §6-24) |

## 3. 부하/동시성 검증 (T5)

- 자동화 근거: `tests/Feature/E2E/RunbookGateScenarioTest.php::test_ten_concurrent_uploads_all_reach_ready_without_double_execution`
  (동시 업로드 10건 × 8 job = 80 job, Worker 3대 — 이중 실행 0건·전건 READY)
- 행 단위 경합: `tests/Feature/Queue/ClaimConcurrencyTest.php` (SKIP LOCKED·locks PK 이중 lease 차단·priority 정렬)
- 실 환경 부하 테스트(목표 처리량·TC 병목 리포트)는 운영 인프라 확정 후 별도 수행 — **미완, M5 잔여 항목**

## 4. 모니터링 지표 10종 ↔ 대시보드 매핑

`GET /admin/workflows/dashboard` (`DashboardQueryService`, 10초 캐시) 기준.

| # | 지표 | 대시보드 필드 | 상태 |
|---|------|--------------|------|
| 1 | 처리량 (1h/24h) | `metrics.throughput_1h` · `throughput_24h` | 구현 |
| 2 | 대기열 깊이 (상태별 job 수) | `jobs.{WAITING,READY,RUNNING,RETRY,FAILED,TIMEOUT}` · `queue.tc_queue_length` | 구현 |
| 3 | 평균 처리 시간 | `metrics.avg_processing_sec` · `p95_processing_sec` | 구현 |
| 4 | Worker 상태 | `workers.{ONLINE,BUSY,OFFLINE,ERROR,DISABLED}` | 구현 |
| 5 | 에러율 | `metrics.failure_rate` | 구현 |
| 6 | 재시도 수 | `metrics.retry_rate` | 구현 |
| 7 | 리소스 (CPU/메모리/디스크) | 앱 외부 — 인프라 모니터링(노드별) 연동 | **외부, M5 잔여 항목** |
| 8 | Lease 회수 이벤트 | `workflow_job_histories` note(`lease_expired`/`worker_offline`) 집계로 조회 가능 — 대시보드 미노출 | 부분 |
| 9 | 장애 이벤트 | `banners` (OFFLINE Worker·필수 job FAILED — ADR-0002 실시간 파생) | 구현 |
| 10 | Index 상태 (INDEXED/STALE/RETRY) | `search_index_states` 집계 — 대시보드 미노출 (콘텐츠 상세에는 노출) | 부분 |

> 8·10번의 대시보드 필드 추가는 openapi.yaml 계약 개정이 선행돼야 한다(임의 필드 추가 금지).

## 5. 알림 발송

- 대시보드 배너(HIGH): OFFLINE Worker · 필수 job FAILED — 구현·테스트 완료
- 이메일/외부 채널 발송: alerts 영속화와 함께 **보류 (ADR-0002)** — DB 스펙 개정 후 진행

## 6. 실 검색엔진 검증 (ADR-0005 운영 전환)

1. `WORKFLOW_SEARCH_DRIVER=elasticsearch|opensearch` + `WORKFLOW_SEARCH_HOSTS=...` (운영 값은 배포 환경 변수로만 — .env 커밋 금지)
2. 바인딩 확인: `app(SearchIndexClient::class)` 가 실제 driver 인스턴스인지
3. smoke 문서 upsert → get 왕복 일치 (`workflow-smoke-test-{timestamp}` ID 패턴)
4. 샘플 콘텐츠 업로드 → INDEX job SUCCESS → `search_index_states` INDEXED → 엔진에서 `content-{id}` 조회
5. 검증 이력: 2026-07-08 로컬 Docker ES 8.17 기준 전 항목 통과. **운영 원격 클러스터 재검증은 접속 정보 확보 후 수행 — M5 잔여 항목**
- 테스트 환경은 phpunit.xml이 dev driver로 고정한다 (`WORKFLOW_SEARCH_DRIVER=dev`)

## 7. 배포·복구 절차

- Worker 배포: disable(drain=true) → 신규 Claim 중단·진행 중 완료 대기 → 종료 → 배포 → enable (Queue Worker Spec §13)
- Scheduler: 단일 실행은 pg advisory lock이 보장 — 중복 기동은 두 번째 프로세스가 자연 차단
- 복구 후 검증 (Runbook §20):
  - [ ] Worker 전원 ONLINE/BUSY (OFFLINE·ERROR 0)
  - [ ] READY Queue 감소 추세 · RUNNING 진행률 갱신 정상
  - [ ] FAILED·TIMEOUT 증가 정지 · Lock 회수 반복 중단
  - [ ] 신규 콘텐츠 1건 업로드 → READY 전환 end-to-end 확인
  - [ ] Search Index INDEXED 회복 · STALE 잔량 소진 중
  - [ ] 수동 조작 전건 admin_audit_logs에 사유와 함께 기록됨
  - [ ] 장애 영향 콘텐츠 재시도/재처리 → READY 확인
- 백업/복구 리허설(DB·MASTER zone): 운영 인프라 확정 후 수행 — **미완, M5 잔여 항목**

## 8. 게이트 판정 요약

| 영역 | 판정 |
|------|------|
| CI 게이트 5종 | 통과 (자동화) |
| Runbook 리허설 7종 | 테스트 레벨 전건 커버 — 실 환경 리허설은 인프라 확정 후 |
| 부하 T5 | 테스트 레벨 커버 — 실 부하는 인프라 확정 후 |
| 지표 10종 | 8/10 대시보드·DB 조회 가능 (리소스 지표는 외부, 2종은 계약 개정 대기) |
| 알림 | 배너 구현 — 외부 발송은 ADR-0002 보류 |
| 실 검색엔진 | 로컬 실 ES 검증 완료 — 운영 클러스터 재검증 대기 |
| 백업/복구 | 미수행 — 운영 인프라 필요 |
