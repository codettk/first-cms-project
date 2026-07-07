# ADR-0001: VIDEO_INGEST TC 스텝 config.profile 값은 profile code를 사용한다

- 상태: 채택 (2026-07-07)
- 관련 문서: Migration Seed Specification §10.2 · Transcode Profile Specification §15

## 문제 (문서 충돌)

- Migration Seed Specification §10.2의 템플릿 시드 예시는 TC 스텝 config를 `{"profile": "720p"}`로 표기한다.
- Transcode Profile Specification §15는 job payload/config가 **profile_code**(`VIDEO_PROXY_720P`)로 프로파일을 참조하고, Worker가 code로 `transcode_profiles`를 조회한다고 명시한다.

## 결정

`workflow_template_steps.config`의 TC 스텝은 `{"profile": "VIDEO_PROXY_720P"}` (profile code)를 사용한다.

## 근거

1. Transcode Profile Spec이 프로파일 참조 체계의 원 소유 문서다 — `720p`는 `variant_key`이지 프로파일 식별자가 아니다.
2. Handler는 `transcode_profiles.code`로 조회하며, code는 UNIQUE라 모호성이 없다. `variant_key`는 여러 프로파일이 공유할 수 있다(예: 720p 단일/HLS).
3. Migration Seed Spec §10.2는 "예시"로 표기되어 있어 규범성이 낮다.

## 영향

- `WorkflowTemplateSeeder`의 VIDEO_INGEST TC 스텝 config = `['profile' => 'VIDEO_PROXY_720P']`.
- TC Handler는 config/payload의 `profile` 값을 code로 간주해 조회한다. 비활성 프로파일이면 거부(ProfileCompiler).
