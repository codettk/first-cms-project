<?php

namespace App\Services\Workflow\Search;

use RuntimeException;

/**
 * 검색엔진 연동 오류 — IndexJobHandler가 SEARCH_ENGINE_ERROR로 분류해 재시도한다.
 * 메시지에 credential(비밀번호·api key·host URL userinfo)을 포함하지 않는다 (ADR-0005).
 */
class SearchIndexException extends RuntimeException {}
