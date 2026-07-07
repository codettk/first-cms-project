<?php

namespace App\Services\Workflow;

/**
 * fail_reason_code ↔ 사용자 안내 문구 단일 맵 — Worker Agent Spec §16 · Controller Service Spec §6.
 * API·화면·알림이 공유한다.
 */
class FailReasonCatalog
{
    /** @var array<string, array{message: string, action: string}> */
    private const array CATALOG = [
        'TEMP_FILE_MISSING' => ['message' => '업로드 파일을 찾을 수 없습니다.', 'action' => '재업로드가 필요합니다.'],
        'SIZE_MISMATCH' => ['message' => '업로드 파일 크기가 등록 정보와 다릅니다.', 'action' => '재업로드가 필요합니다.'],
        'CHECKSUM_MISMATCH' => ['message' => '파일 무결성 검증에 실패했습니다.', 'action' => '스토리지 점검 후 재시도하세요.'],
        'PERMANENT_CORRUPT' => ['message' => '손상된 파일입니다.', 'action' => '정상 파일로 재업로드가 필요합니다.'],
        'UNSUPPORTED_FORMAT' => ['message' => '지원하지 않는 파일 형식입니다.', 'action' => '지원 형식으로 변환 후 재업로드하세요.'],
        'ENCRYPTED_DOC' => ['message' => '암호화된 문서입니다.', 'action' => '암호 해제 후 재업로드하세요.'],
        'MEDIA_TYPE_UNDETERMINED' => ['message' => '미디어 유형을 판별할 수 없습니다.', 'action' => '파일 형식을 확인 후 재업로드하세요.'],
        'NETWORK_ERROR' => ['message' => '일시적 네트워크 오류입니다.', 'action' => '자동 재시도됩니다.'],
        'DB_CONN_LOST' => ['message' => '데이터베이스 연결이 끊어졌습니다.', 'action' => '자동 재시도됩니다.'],
        'OOM' => ['message' => '메모리 부족으로 실패했습니다.', 'action' => 'Worker 자원을 확인하세요.'],
        'UNEXPECTED_EXCEPTION' => ['message' => '예기치 못한 오류가 발생했습니다.', 'action' => '로그를 확인하세요.'],
        'NO_HANDLER' => ['message' => '해당 작업 유형의 처리기가 없습니다.', 'action' => 'Worker 설정을 확인하세요.'],
        'LEASE_EXPIRED' => ['message' => 'Worker 응답이 중단되어 작업을 회수했습니다.', 'action' => '자동 재배정됩니다.'],
        'WORKER_CRASH' => ['message' => 'Worker 장애로 작업이 중단되었습니다.', 'action' => '자동 재배정됩니다.'],
        'STORAGE_IO' => ['message' => '스토리지 입출력 오류입니다.', 'action' => '스토리지 상태를 즉시 점검하세요.'],
        'DISK_FULL' => ['message' => '스토리지 용량이 부족합니다.', 'action' => '용량 확보 후 재시도하세요.'],
        'FFMPEG_FAILED' => ['message' => '변환 도구 실행에 실패했습니다.', 'action' => '재시도 후 반복되면 도구 로그를 확인하세요.'],
        'TOOL_TIMEOUT' => ['message' => '작업이 제한 시간을 초과했습니다.', 'action' => '재시도 또는 timeout 설정을 확인하세요.'],
        'INDEX_UNAVAILABLE' => ['message' => '검색엔진에 연결할 수 없습니다.', 'action' => '검색엔진 상태를 점검하세요.'],
        'REQUIRED_RENDITION_MISSING' => ['message' => '필수 산출물이 없어 게시할 수 없습니다.', 'action' => '해당 생성 작업을 수동 재실행하세요.'],
        'PROFILE_MISSING' => ['message' => '변환 프로파일이 지정되지 않았습니다.', 'action' => '템플릿 설정을 확인하세요.'],
        'PROFILE_INACTIVE' => ['message' => '비활성 변환 프로파일입니다.', 'action' => '프로파일 활성화 또는 템플릿 수정이 필요합니다.'],
        'PUBLISH_BLOCKED' => ['message' => '선행 작업이 완료되지 않아 게시할 수 없습니다.', 'action' => '파이프라인 상태를 확인하세요.'],
        'CONTENT_NOT_PROCESSING' => ['message' => '콘텐츠가 처리 중 상태가 아닙니다.', 'action' => '콘텐츠 상태를 확인하세요.'],
    ];

    /** @return array{message: string, action: string} */
    public function describe(?string $code): array
    {
        return self::CATALOG[$code] ?? ['message' => '알 수 없는 오류입니다.', 'action' => '로그를 확인하세요.'];
    }

    public function has(string $code): bool
    {
        return array_key_exists($code, self::CATALOG);
    }
}
