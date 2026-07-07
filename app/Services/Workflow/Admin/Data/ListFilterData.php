<?php

namespace App\Services\Workflow\Admin\Data;

use Illuminate\Http\Request;

/**
 * 목록 필터 DTO — sort/filter는 화이트리스트로만 수용한다 (Controller Service Spec §9).
 */
final readonly class ListFilterData
{
    /**
     * @param array<string, mixed> $filters
     */
    private function __construct(
        public array $filters,
        public int $page,
        public int $perPage,
        public string $sort,
        public string $direction,
    ) {}

    /**
     * @param list<string> $allowedFilters
     * @param list<string> $allowedSorts
     */
    public static function fromRequest(
        Request $request,
        array $allowedFilters,
        array $allowedSorts,
        string $defaultSort = 'created_at',
    ): self {
        $sortParam = (string) $request->query('sort', "-{$defaultSort}");
        $direction = str_starts_with($sortParam, '-') ? 'desc' : 'asc';
        $sortColumn = ltrim($sortParam, '-');

        if (! in_array($sortColumn, $allowedSorts, true)) {
            $sortColumn = $defaultSort;
            $direction = 'desc';
        }

        return new self(
            filters: array_filter(
                $request->only($allowedFilters),
                fn ($v) => $v !== null && $v !== ''
            ),
            page: max(1, (int) $request->query('page', '1')),
            perPage: min(200, max(1, (int) $request->query('per_page', '20'))),
            sort: $sortColumn,
            direction: $direction,
        );
    }
}
