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
| 8 | Lease 회수 이벤트 | `metrics.lease_reclaims_24h` (`workflow_job_histories` note `lease_expired`/`worker_offline` 24h 집계) | 구현 |
| 9 | 장애 이벤트 | `banners` (OFFLINE Worker·필수 job FAILED — ADR-0002 실시간 파생) | 구현 |
| 10 | Index 상태 (INDEXED/STALE/PENDING/FAILED) | `search_index` (status별 집계) — 콘텐츠 상세에도 노출 | 구현 |

> 8·10번은 openapi.yaml DashboardData 계약 개정과 함께 대시보드에 노출됐다 (지표 9/10 구현 — 리소스 지표만 외부).

## 5. 알림 발송

- 대시보드 배너(HIGH): OFFLINE Worker · 필수 job FAILED — 구현·테스트 완료 (실시간 파생 유지)
- 알림센터(영속화): `workflow_alerts` 테이블 + `GET /alerts`·`POST /alerts/{id}/ack`(멱등·감사 기록)
  \+ 콘솔 알림센터 화면 — **구현 (ADR-0006, ADR-0002 개정)**
- 이메일 발송: HIGH 이메일 채널 이벤트(필수 실패 등) — `WORKFLOW_ALERT_MAIL_TO` 설정 시 발송,
  미설정 시 생략. 발송 실패는 경고 로그만(Scheduler 비중단)
- 이벤트 소스: Scheduler 틱의 REQUIRED_JOB_FAILED·WORKER_OFFLINE부터 — Wireframe §13의
  나머지 이벤트(Storage I/O·Master 누락 등)는 감지 지점 구현 시 동일 경로로 추가
- 자동화 근거: `tests/Feature/Admin/AlertApiTest.php`

## 6. 실 검색엔진 검증 (ADR-0005 운영 전환)

1. `WORKFLOW_SEARCH_DRIVER=elasticsearch|opensearch` + `WORKFLOW_SEARCH_HOSTS=...` (운영 값은 배포 환경 변수로만 — .env 커밋 금지)
2. 바인딩 확인: `app(SearchIndexClient::class)` 가 실제 driver 인스턴스인지
3. smoke 문서 upsert → get 왕복 일치 (`workflow-smoke-test-{timestamp}` ID 패턴)
4. 샘플 콘텐츠 업로드 → INDEX job SUCCESS → `search_index_states` INDEXED → 엔진에서 `content-{id}` 조회
5. 검증 이력: 2026-07-08 로컬 Docker ES 8.17 기준 전 항목 통과. 2026-07-09 v1.0-mvp 코드
   기준 재검증 — 바인딩(ElasticsearchSearchIndexClient)·smoke upsert/get 왕복 통과.
   **운영 원격 클러스터 재검증은 접속 정보 확보 후 수행 — M5 잔여 항목 (§9)**
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

## 8. 미구현 선택 job 잔여 대기 정책 (의도된 상태)

**OCR·WAVEFORM은 구현 완료** (Job Type Def §3.9 · Transcode Profile Spec §6 — OCR/AUDIO
worker_type이 소비, OCR SUCCESS 시 INDEXED→STALE 재색인 예약). 잔여 미구현 유형은
**STT**(AUDIO 템플릿 선택 step, 작업 정의 §3 미작성)와 **AI_ANALYSIS**(템플릿 미포함)다.
STT의 동작은 다음과 같으며 **운영상 의도된 잔여 대기**다.

- Scheduler는 선행 job SUCCESS 시 선택 job도 WAITING→READY로 승격한다 (인스턴스 SUCCESS 종결 후 포함).
- 어떤 worker_type의 supported_job_types에도 포함되지 않으므로 claim되지 않고 READY로 대기한다.
- PUBLISH 판정은 is_required=true만 집계(SM Spec §8)하므로 콘텐츠 READY 전환을 차단하지 않는다.
- 자동화 근거: `tests/Feature/Queue/OptionalJobPolicyTest.php` · `tests/Feature/E2E/AudioPipelineTest.php`
- 운영 주의: 대시보드 대기열 지표(`jobs.READY`)에 이 잔여 대기 수가 상시 포함된다 —
  READY 적체 알람 임계값 설정 시 선택 job 잔여분을 기저값으로 반영할 것.
- 해제 시점: STT 작업 정의(엔진 선정 포함) 확정·Handler 구현 후 worker 매핑에 추가하면
  기존 READY 잔여 job부터 자연 소진된다.

**HLS·다중 화질(480p/360p) 정책** — Transcode Profile Spec §14에 따라 예비 정의만 두고
`is_active=false`를 유지한다(**확정 결정**). 활성화는 다중 화질/스트리밍 요구사항 확정과
스펙 §14 개정이 선행돼야 하며, 가드는
`MvpScopeGuardTest::test_only_720p_profile_is_active_in_video_proxies`가 잠근다.

## 9. 인프라 의존 잔여 항목 (차단 사유 · 필요 입력)

코드만으로 완결할 수 없는 항목이다 — 아래 입력이 제공되면 각 절(§2·§3·§6·§7) 절차를 즉시 수행한다.

| 항목 | 차단 사유 | 필요 입력 |
|------|----------|----------|
| 운영 검색엔진 재검증 (§6) | 운영 클러스터 접속 정보 없음 | 운영 `WORKFLOW_SEARCH_HOSTS`·driver 종류(ES/OpenSearch)·인증(basic 또는 API key, 배포 환경변수로만 전달) |
| 실환경 Runbook 리허설 7종 (§2) | 운영/스테이징 인프라 미확정 | Worker·Scheduler 배포 노드, zone mount 구성, 리허설 window |
| 실 부하 테스트 T5 (§3) | 동일 | 목표 처리량, 샘플 미디어 세트, TC 노드 사양 |
| 백업/복구 리허설 (§7) | 동일 | DB 백업 정책, MASTER zone 스냅샷 수단 |
| 리소스 지표 (§4-7) | 앱 외부 — 노드 모니터링 스택 필요 | 모니터링 시스템(Prometheus/CloudWatch 등) 연동 대상 |

## 10. 게이트 판정 요약

| 영역 | 판정 |
|------|------|
| CI 게이트 5종 | 통과 (자동화) |
| Runbook 리허설 7종 | 테스트 레벨 전건 커버 — 실 환경 리허설은 인프라 확정 후 (§9) |
| 부하 T5 | 테스트 레벨 커버 — 실 부하는 인프라 확정 후 (§9) |
| 지표 10종 | 9/10 대시보드 노출 (리소스 지표만 외부 — §9) |
| 알림 | 영속화·ack API·이메일 발송 구현 (ADR-0006) |
| 선택 작업 | OCR·WAVEFORM 구현 + STALE 재색인 루프 — STT·AI_ANALYSIS만 정의 대기 (§8) |
| 실 검색엔진 | 로컬 실 ES 검증 완료 — 운영 클러스터 재검증 대기 (§9) |
| 백업/복구 | 미수행 — 운영 인프라 필요 (§9) |
