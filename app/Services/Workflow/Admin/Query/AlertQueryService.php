<?php

namespace App\Services\Workflow\Admin\Query;

use App\Models\WorkflowAlert;

/**
 * 알림 목록 조회 — Admin API Spec §21 (severity·acknowledged·event_type·기간 필터).
 */
class AlertQueryService
{
    /** @return list<array<string, mixed>> */
    public function list(array $filters): array
    {
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        return WorkflowAlert::query()
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['event_type'] ?? null, fn ($q, $v) => $q->where('event_type', $v))
            ->when(
                array_key_exists('acknowledged', $filters) && $filters['acknowledged'] !== null && $filters['acknowledged'] !== '',
                fn ($q) => filter_var($filters['acknowledged'], FILTER_VALIDATE_BOOLEAN)
                    ? $q->whereNotNull('acknowledged_at')
                    : $q->whereNull('acknowledged_at'),
            )
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (WorkflowAlert $alert) => $alert->toItem())
            ->values()
            ->all();
    }
}
