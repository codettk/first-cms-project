<?php

namespace App\Services\Workflow\Admin\Data;

/**
 * 조작 결과 DTO — Controller Service Spec §15. CommandResultResource가 직렬화한다.
 */
final readonly class CommandResult
{
    /**
     * @param array<string, mixed> $data
     * @param list<string> $availableActions
     */
    private function __construct(
        public bool $success,
        public array $data,
        public array $availableActions,
        public int $httpStatus = 200,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param list<string> $availableActions
     */
    public static function ok(array $data, array $availableActions = [], int $httpStatus = 200): self
    {
        return new self(true, $data, $availableActions, $httpStatus);
    }

    /** @return array<string, mixed> */
    public function toResponse(): array
    {
        return [
            'success' => true,
            'data' => [...$this->data, 'available_actions' => $this->availableActions],
        ];
    }
}
