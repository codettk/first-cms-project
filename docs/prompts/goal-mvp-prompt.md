Frame CMS/MAM Workflow Engine의 MVP 구현을 끝까지 진행해줘.

단, 절대 네 판단으로 범위를 확장하지 마라.
반드시 프로젝트에 이미 배치된 문서, CLAUDE.md, agents, skills, design-handoff 문서만 기준으로 작업해라.

## 0. 가장 먼저 해야 할 일

아직 코드를 수정하지 말고, 먼저 아래 파일을 읽어라.

```text
CLAUDE.md
docs/design-handoff/content-management-system/HANDOFF_README.md
docs/design-handoff/content-management-system/Revision Checklist.dc.html
docs/design-handoff/content-management-system/MVP Scope Git Strategy.dc.html
docs/design-handoff/content-management-system/WBS Milestone Plan.dc.html
docs/design-handoff/content-management-system/Implementation Roadmap.dc.html
```

그 다음 현재 프로젝트 구조를 확인해라.

확인할 것:

```text
app/
database/
routes/
tests/
composer.json
package.json
artisan
.env.example
```

그리고 아래 형식으로 먼저 보고해라.

```text
## Goal 초기 분석 보고

1. 읽은 문서
2. 현재 프로젝트 구조
3. Laravel / PHP / PostgreSQL 관련 확인 사항
4. MVP 구현 범위
5. 제외해야 할 범위
6. 사용할 agents / skills
7. 예상 작업 단계
8. 위험 요소
9. 지금 바로 시작할 Sprint 1 작업
```

초기 분석 이후에는 아래 범위 안에서만 순차적으로 작업을 진행해라.

---

# 1. 이번 Goal의 최종 목표

이번 Goal의 최종 목표는 아래 MVP VIDEO 파이프라인을 구현하는 것이다.

```text
UPLOAD / REGISTER
→ TM
→ VERIFY
→ MA
→ TC
→ CA
→ INDEX
→ PUBLISH
→ CLEANUP
```

MVP에서 반드시 구현할 것:

```text
1. Workflow DB 기반
2. PHP Enum
3. Migration
4. Seeder
5. Model / Relation / Factory
6. StateMachine
7. Workflow Instance / Job 생성
8. Scheduler
9. Worker Claim / Lock / Heartbeat / Fencing
10. Storage / Rendition / Profile Service
11. MVP Job Handler
   - TM
   - VERIFY
   - MA
   - TC
   - CA
   - INDEX
   - PUBLISH
   - CLEANUP
12. Admin Query API
13. Admin Command API
14. Audit Log
15. 기본 Admin UI
16. 테스트
17. Runbook 기준 장애 시나리오 검증
```

---

# 2. 이번 Goal에서 절대 구현하지 말 것

아래 항목은 MVP 제외 범위다.
내가 명시적으로 요청하지 않는 한 구현하지 마라.

```text
OCR
STT
AI_ANALYSIS
HLS
다중 화질 480p / 360p
WAVEFORM
문서 OCR 고도화
media_texts 테이블 분리
IMAGE 파이프라인
AUDIO 파이프라인
DOC 파이프라인
release-lock UI 기본 노출
Worker disable / enable / clear-error UI 기본 노출
```

주의:

* DB enum이나 seed에 미래 확장값이 존재할 수는 있다.
* 하지만 MVP 코드 경로로 구현하지 마라.
* 특히 OCR, STT, AI_ANALYSIS, HLS, WAVEFORM Handler는 만들지 마라.
* DOC_PREVIEW, TEXT_EXTRACT, IMAGE_TC, AUDIO_TC도 M6 확장으로 남겨라.

---

# 3. 반드시 지켜야 할 Source of Truth

문서 간 충돌이 있으면 아래 순서대로 따른다.

```text
1. Revision Checklist.dc.html
2. MVP Scope Git Strategy.dc.html
3. WBS Milestone Plan.dc.html
4. Implementation Roadmap.dc.html
5. 작업 유형별 기술 스펙 문서
6. 기존 코드
```

기존 코드와 문서가 충돌하면 네 판단으로 바로 고치지 말고 먼저 보고해라.

보고 형식:

```text
## 문서/코드 충돌 보고

- 충돌 위치:
- 충돌 문서:
- 충돌 코드:
- Source of Truth 기준:
- 추천 조치:
- 구현 영향:
```

단, 문서 기준으로 명확한 신규 구현 작업이면 진행해도 된다.

---

# 4. 사용할 작업 방식

요청 전체는 `workflow-auto-router` 기준으로 분류하고, 필요한 경우 아래 agent / skill을 사용해라.

```text
DB / migration / seed / model
→ workflow-db-foundation
→ workflow-db-architect

StateMachine / transition
→ workflow-state-machine
→ workflow-state-machine-engineer

Scheduler / Queue / Worker / Storage / Rendition / Profile / Handler
→ workflow-worker
→ workflow-worker-engineer

Admin API / OpenAPI / Controller / Service
→ workflow-admin-api
→ workflow-admin-api-engineer

Admin UI / Design System / Component / Screen
→ workflow-admin-ui
→ workflow-admin-ui-engineer

Test / Review / Validation / Runbook
→ workflow-review
→ workflow-qa-reviewer
```

작업 전에 반드시 해당 agent/skill의 문서 읽기 규칙을 따른다.

---

# 5. 구현 순서

아래 순서를 절대 건너뛰지 마라.

## Phase 1. DB Foundation

작업 범위:

```text
1. PHP Enum 생성
2. Migration 작성
3. PostgreSQL CHECK 제약
4. Partial Index
5. Trigger
6. Seeder
7. Model
8. Factory
9. DB 테스트
```

반드시 반영할 것:

```text
workflow_jobs.priority
workflow_jobs.cancel_requested_at
workflow_job_progresses
media_renditions.variant_key
admin_audit_logs
workflow_job_allowed_transitions
transcode_profiles.media_type = VIDEO / IMAGE / AUDIO / DOC / COMMON
contents.media_type = VIDEO / IMAGE / AUDIO / DOC
```

금지:

```text
contents.media_type에 COMMON 추가 금지
workflow_jobs에 progress 저장 금지
variant_key 없는 media_renditions unique index 금지
```

완료 기준:

```text
php artisan migrate:fresh --seed 성공
DB 관련 테스트 통과
seed 멱등성 확인
DOCUMENT_INGEST depends_on = MA 확인
COMMON은 transcode_profiles 전용 확인
```

---

## Phase 2. StateMachine / Workflow 생성

작업 범위:

```text
1. AbstractStateMachine
2. JobStateMachine
3. ContentStateMachine
4. WorkflowInstanceStateMachine
5. WorkerStateMachine
6. SearchIndexStateMachine
7. TransitionRunner
8. WorkflowInstanceFactory
9. Template 기반 Jobs / Dependencies 생성
10. 전이 테스트
```

반드시 지킬 것:

```text
StateMachine 내부에서 DB::transaction() 열지 않기
직접 update(['status' => ...]) 금지
CAS update 사용
history 기록
Worker 완료 기록에는 worker fencing 조건 포함
```

완료 기준:

```text
허용 전이 테스트 통과
금지 전이 테스트 통과
CAS 충돌 테스트 통과
VIDEO 템플릿 인스턴스 생성 테스트 통과
```

---

## Phase 3. Scheduler / Worker 기반

작업 범위:

```text
1. workflow:scheduler command
2. WAITING → READY 전환
3. RETRY → READY 전환
4. TIMEOUT 감시
5. expired lock 회수
6. Worker heartbeat 감시
7. SKIPPED 전파
8. advisory lock 기반 Scheduler 단일 실행
9. workflow:worker command
10. Worker 등록
11. Job Claim
12. Job Lock
13. Heartbeat
14. Lease fencing
15. Graceful shutdown
```

반드시 지킬 것:

```text
Job Claim은 FOR UPDATE SKIP LOCKED 사용
Claim 정렬은 priority DESC, created_at ASC
초기 구현은 Worker process 1개 = Job 1개
동시성은 supervisor numprocs 기준
current_job_id는 단일 Job 전제에서만 사용
```

완료 기준:

```text
N-Worker 동시 claim 중복 0건
lease 만료 회수 테스트 통과
늦은 SUCCESS 결과 폐기 테스트 통과
SIGTERM drain 테스트 통과
```

---

## Phase 4. Storage / Rendition / Profile

작업 범위:

```text
1. MediaStorageService
2. zone별 path resolver
3. .tmp/{job_id} 작업 후 atomic rename
4. Master 보호 가드
5. RenditionService
6. variant_key 기반 upsert
7. TranscodeProfile 조회
8. ProfileCompiler
9. file stat / checksum 저장
```

반드시 지킬 것:

```text
Master Storage 수정/삭제 금지
Master direct path API/UI 노출 금지
media_renditions upsert는 variant_key 기준
COMMON profile은 transcode_profiles.media_type에서만 사용
```

완료 기준:

```text
variant_key unique 3종 테스트 통과
Master 변조 차단 테스트 통과
비활성 profile 거부 테스트 통과
```

---

## Phase 5. MVP Job Handler

MVP에서 구현할 Handler만 구현해라.

```text
TM
VERIFY
MA
TC
CA
INDEX
PUBLISH
CLEANUP
```

구현하지 말 것:

```text
IMAGE_TC
AUDIO_TC
DOC_PREVIEW
TEXT_EXTRACT
OCR
WAVEFORM
STT
AI_ANALYSIS
HLS
```

Handler별 완료 기준:

```text
TM
- TEMP → MASTER
- MASTER rendition 생성
- checksum / file_size 저장

VERIFY
- 파일 존재 확인
- 크기 확인
- checksum 확인
- MIME / 확장자 확인
- 손상 파일 분류

MA
- media_info 추출
- content.media_type 확정
- VIDEO 기준 처리

TC
- 720p proxy 생성
- progress 저장
- PROXY_VIDEO rendition 생성

CA
- thumbnail / catalog 생성
- THUMBNAIL / CATALOG rendition 생성

INDEX
- search_index_states 갱신
- 실제 검색엔진 연동 기준
- Mock INDEX는 개발 테스트용으로만 허용

PUBLISH
- 필수 job success 확인
- 필수 rendition 존재 확인
- INDEX success 확인
- content READY 전환

CLEANUP
- temp / progress / tmp 정리
- Master 삭제 금지
```

완료 기준:

```text
영상 1건 업로드 → READY 자동 전환
TC 진행률 표시 가능
손상 파일 실패 분기 확인
Worker kill 후 재처리 성공
```

---

## Phase 6. Admin API

작업 범위:

```text
1. routes/admin.php
2. Permission / Gate
3. FormRequest
4. QueryService
5. CommandService
6. AvailableActionService
7. AuditLogger
8. Resource
9. Exception Handler
10. OpenAPI 계약 대조
```

MVP Admin API 포함:

```text
Dashboard 조회
Contents 조회
Jobs 조회
Workers 조회
Job retry
Job cancel
release-lock
Worker disable
Worker enable
Worker clear-error
Audit Log 기록
```

반드시 지킬 것:

```text
jobs/retry-batch는 jobs/{job}보다 먼저 선언
GET 요청은 admin_audit_logs 저장 금지
POST/PATCH/DELETE 조작 API만 감사 로그 저장
audit.context / audit.write 분리
available_actions는 서버에서만 계산
Master direct path 노출 금지
OpenAPI와 Route/FormRequest/Resource/Exception Handler 일치
```

완료 기준:

```text
권한 테스트 통과
validation 테스트 통과
409 상태 충돌 테스트 통과
audit log 테스트 통과
OpenAPI 계약 테스트 통과
```

---

## Phase 7. Admin UI

MVP Admin UI에 포함할 것:

```text
Dashboard
콘텐츠 처리 목록
콘텐츠 처리 상세
실패 Job 확인
실패 Job 재시도
Worker 상태 조회
```

기본 화면에서 노출하지 말 것:

```text
Lock 강제 회수 버튼
Worker disable 버튼
Worker enable 버튼
Worker clear-error 버튼
```

반드시 지킬 것:

```text
서버 available_actions만 기준으로 버튼 노출
프론트에서 상태 전이 로직 중복 구현 금지
Master direct path 노출 금지
Design System 문서 참고
Admin UI Wireframe 문서 참고
```

완료 기준:

```text
운영자가 실패 Job 확인 → 사유 열람 → 재시도 → READY 회복 시나리오 완료
Worker 상태 조회 가능
Master path 노출 0건
```

---

## Phase 8. 테스트 / 검증

반드시 검증할 것:

```text
DB schema tests
CHECK / FK / trigger tests
StateMachine transition tests
Scheduler tests
Worker claim concurrency tests
Lease / fencing tests
Handler tests
Admin API tests
Audit log tests
OpenAPI contract tests
Admin UI scenario tests
E2E MVP pipeline tests
Runbook scenario tests
```

최소 통과 기준:

```text
php artisan test 통과
migrate:fresh --seed 통과
VIDEO 업로드 → READY E2E 통과
동시 Worker claim 중복 0건
Master path 노출 0건
GET audit write 0건
```

---

# 6. 커밋 / 작업 단위

작업은 가능한 아래 커밋 단위에 맞춰 진행해라.

```text
1. feat(workflow): add workflow enums, migrations and seeds
2. feat(workflow): add models, casts, relations and factories
3. feat(workflow): implement state machines with transition tests
4. feat(workflow): add instance/job creation from templates
5. feat(workflow): implement scheduler and lock recovery
6. feat(worker): implement base loop, claim and heartbeat
7. feat(worker): add storage, rendition and profile services
8. feat(worker): add MVP handlers TM/VERIFY/MA/PUBLISH/CLEANUP
9. feat(worker): add media handlers TC/CA and INDEX
10. feat(admin): add workflow query APIs
11. feat(admin): add command APIs with audit logs
12. feat(admin): add dashboard/list/detail UI
13. test(workflow): add e2e, runbook validation and openapi contract
```

주의:

* 커밋은 가능하면 작게 나눠라.
* 단, 내가 별도로 커밋 실행을 요청하지 않았다면 실제 git commit은 하지 말고 변경 파일만 보고해라.
* git add / commit은 내가 승인한 뒤에만 수행해라.

---

# 7. 자기 판단 금지 규칙

아래 행동은 금지한다.

```text
문서에 없는 테이블 추가
문서에 없는 enum 추가
MVP 제외 기능 구현
DOC/IMAGE/AUDIO 파이프라인 구현
OCR/STT/AI_ANALYSIS/HLS 구현
상태 전이 로직 임의 변경
프론트에서 상태 판정 로직 임의 구현
Master path 직접 노출
GET 요청에서 audit log 저장
schema 변경을 문서 확인 없이 수행
기존 CMS 기능을 광범위하게 리팩터링
관련 없는 파일 수정
테스트 실패를 무시하고 다음 단계 진행
```

판단이 필요한 경우에는 구현하지 말고 보고해라.

```text
## 판단 필요 보고

- 판단이 필요한 내용:
- 관련 문서:
- 가능한 선택지:
- 추천안:
- 영향:
```

---

# 8. 진행 방식

각 Phase마다 아래 순서를 지켜라.

```text
1. 관련 문서 읽기
2. 기존 코드 확인
3. 작업 계획 보고
4. 필요한 파일만 수정
5. 테스트 실행
6. 결과 보고
7. 다음 Phase 진행 가능 여부 판단
```

단, 문서와 코드가 명확하고 테스트가 통과하면 다음 Phase까지 이어서 진행해도 된다.

하지만 아래 경우에는 반드시 중단하고 보고해라.

```text
schema 충돌
문서 간 충돌
테스트 실패
migration 실패
기존 코드 구조가 예상과 다름
외부 도구/검색엔진/스토리지 환경 미구성
보안 또는 데이터 삭제 위험
```

---

# 9. 최종 완료 조건

Goal은 아래 조건을 만족해야 완료다.

```text
1. VIDEO MVP pipeline E2E 성공
2. migrate:fresh --seed 성공
3. Workflow 관련 테스트 통과
4. StateMachine 전이 테스트 통과
5. Worker claim concurrency 테스트 통과
6. Admin API 테스트 통과
7. Admin UI 기본 시나리오 통과
8. OpenAPI 계약 테스트 통과
9. Audit Log 테스트 통과
10. Master direct path 노출 0건
11. MVP 제외 기능 미구현 확인
12. 변경 파일 목록 보고
13. 남은 이슈 및 다음 확장 계획 보고
```

---

# 10. 최종 보고 형식

Goal 완료 또는 중단 시 아래 형식으로 보고해라.

```text
## Goal 진행 결과

### 1. 진행한 Phase
- Phase:

### 2. 사용한 문서
- 문서:

### 3. 생성한 파일
- 파일:

### 4. 수정한 파일
- 파일:

### 5. 실행한 명령어
- 명령어:

### 6. 테스트 결과
- 결과:

### 7. 완료된 기능
- 기능:

### 8. 미완료 / 중단 사유
- 사유:

### 9. 문서와 다른 점
- 차이:

### 10. 다음 추천 작업
- 작업:

### 11. 내가 확인해야 할 사항
- 확인 사항:
```

이 Goal의 목적은 “가능한 많이 만드는 것”이 아니라, 문서에 정의된 MVP 범위를 정확하게 구현하는 것이다.

범위를 넘지 말고, 문서 기준을 지켜서, 테스트 가능한 상태로 끝내라.