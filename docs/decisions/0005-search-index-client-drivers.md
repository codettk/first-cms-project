# ADR-0005: 검색 색인 클라이언트는 env driver로 선택한다 (dev/elasticsearch/opensearch)

- 상태: 채택 (2026-07-08)
- 관련 문서: Job Type Definition §3.11 · Worker Agent Spec §9 · Implementation Roadmap Phase 5

## 문제

- Roadmap Phase 5: "Mock INDEX는 개발 환경 테스트용으로만 허용 — 운영 MVP 게이트는
  실제 INDEX 성공 기준". 그러나 설계 문서 어디에도 검색엔진 제품이 명시되지 않았고,
  저장소에도 관련 패키지/설정이 없다.

## 결정

1. 검색엔진 제품은 운영 시점까지 확정하지 않고 **Elasticsearch 8.x / OpenSearch 2.x
   두 driver를 모두 제공**한다 (사용자 결정 2026-07-08). 문서 API(`PUT`/`GET
   /{index}/_doc/{id}`)가 동일하므로 공통 베이스 `AbstractHttpSearchIndexClient`에
   구현하고 인증 헤더만 분기한다 — ES는 api_key(`ApiKey` 헤더) 우선, OpenSearch는 basic만.
2. 접속은 **Laravel Http facade(HTTP REST)** — 공식 SDK를 도입하지 않는다.
   upsert/get 2개 계약에는 REST 직접 호출로 충분하고 `Http::fake()` 테스트가 가능하다.
3. driver 선택은 `config/workflow.php` `search` 섹션 + `WORKFLOW_SEARCH_*` env.
   기본 `dev`(DevSearchIndexClient, cache mock). 미지원 driver는 컨테이너 해석 시점에
   `InvalidArgumentException`, host 미설정은 `SearchIndexException`.
4. index 기본 이름 `content_mam`, 문서 ID는 기존 Handler의 `content-{id}` 유지.
   mapping은 MVP에서 명시 생성하지 않고 **dynamic mapping** 사용 — 명시 mapping·인덱스
   부트스트랩은 검색 요구사항 확정 후 후속 결정.
5. 예외 메시지·로그에 credential(비밀번호·api key·host URL userinfo)을 포함하지 않는다.
   연결 실패 메시지의 host는 userinfo를 `***`로 마스킹한다.

## 근거

`IndexJobHandler`는 `SearchIndexClient` 인터페이스에만 의존하므로 바인딩 교체만으로
운영 전환이 가능하다(Handler 무수정 — 색인 실패는 기존 SEARCH_ENGINE_ERROR 재시도
정책에 위임). M3 게이트 선행 확인 항목(연결·mapping·upsert·조회 검증)은 실 클러스터
접속 정보 확보 후 driver 전환 + 샘플 색인으로 검증한다.

## 운영 전환 절차

`.env`에 다음을 설정한다 (`.env`는 커밋 금지 — 키 목록은 `.env.example` 참조):

```env
WORKFLOW_SEARCH_DRIVER=elasticsearch   # 또는 opensearch
WORKFLOW_SEARCH_INDEX=content_mam
WORKFLOW_SEARCH_HOSTS=https://es-node1:9200,https://es-node2:9200
WORKFLOW_SEARCH_USERNAME=              # basic auth
WORKFLOW_SEARCH_PASSWORD=
WORKFLOW_SEARCH_API_KEY=               # Elasticsearch 전용 — basic보다 우선
WORKFLOW_SEARCH_VERIFY_SSL=true
WORKFLOW_SEARCH_TIMEOUT=10
```

- hosts는 콤마 구분 다중 지정 — 연결 실패 시 순서대로 failover.
- 전환 검증: 샘플 콘텐츠 업로드 → INDEX job SUCCESS → `search_index_states`
  INDEXED(index_doc_id/index_version) 확인 → 검색엔진에서 `GET /{index}/_doc/content-{id}` 조회.
