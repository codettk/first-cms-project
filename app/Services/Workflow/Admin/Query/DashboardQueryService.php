<?php

namespace App\Services\Workflow\Admin\Query;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 대시보드 집계 — Controller Service Spec §9 · Admin API Spec §6.
 * banners는 저장 없이 실시간 파생(OFFLINE Worker·필수 job FAILED) — ADR-0002.
 */
class DashboardQueryService
{
    private const int CACHE_SECONDS = 10;

    /** @return array<string, mixed> */
    public function aggregate(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget('workflow:dashboard');
        }

        return Cache::remember('workflow:dashboard', self::CACHE_SECONDS, fn () => $this->build());
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $countsBy = fn (string $table): array => DB::table($table)
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')->pluck('cnt', 'status')
            ->map(fn ($v) => (int) $v)->all();

        $tcQueue = DB::table('workflow_jobs')
            ->whereIn('status', ['READY', 'RETRY'])->where('job_type', 'TC');

        $maxWait = DB::table('workflow_jobs')
            ->whereIn('status', ['READY', 'RETRY'])
            ->selectRaw('EXTRACT(EPOCH FROM (now() - min(created_at)))::int as sec')
            ->value('sec');

        $day = DB::table('workflow_jobs')
            ->where('finished_at', '>=', now()->subDay())
            ->selectRaw("
                count(*) FILTER (WHERE status = 'SUCCESS') as success_cnt,
                count(*) FILTER (WHERE status = 'FAILED') as failed_cnt,
                count(*) FILTER (WHERE status = 'RETRY') as retry_cnt,
                avg(EXTRACT(EPOCH FROM (finished_at - started_at))) FILTER (WHERE status = 'SUCCESS') as avg_sec,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (finished_at - started_at))) as p95_sec
            ")->first();

        $total = ((int) $day->success_cnt) + ((int) $day->failed_cnt);

        $throughput24h = DB::table('workflow_jobs')
            ->where('finished_at', '>=', now()->subDay())
            ->whereIn('status', ['SUCCESS', 'FAILED'])
            ->selectRaw("
                date_trunc('hour', finished_at) as hour,
                count(*) FILTER (WHERE status = 'SUCCESS') as success,
                count(*) FILTER (WHERE status = 'FAILED') as failed
            ")
            ->groupBy('hour')->orderBy('hour')
            ->get()
            ->map(fn ($row) => [
                'hour' => $row->hour,
                'success' => (int) $row->success,
                'failed' => (int) $row->failed,
            ])->all();

        return [
            'contents' => $countsBy('contents'),
            'jobs' => $countsBy('workflow_jobs'),
            'workers' => $countsBy('workflow_worker_agents'),
            'queue' => [
                'tc_queue_length' => $tcQueue->count(),
                'max_wait_sec' => (int) ($maxWait ?? 0),
            ],
            'metrics' => [
                'avg_processing_sec' => (int) round((float) ($day->avg_sec ?? 0)),
                'p95_processing_sec' => (int) round((float) ($day->p95_sec ?? 0)),
                'failure_rate' => $total > 0 ? round(((int) $day->failed_cnt) / $total, 4) : 0,
                'retry_rate' => $total > 0 ? round(((int) $day->retry_cnt) / $total, 4) : 0,
                'throughput_1h' => (int) DB::table('workflow_jobs')
                    ->where('status', 'SUCCESS')
                    ->where('finished_at', '>=', now()->subHour())->count(),
                // Lease 회수 이벤트 — Scheduler reclaim 기록 집계 (Runbook 지표 8)
                'lease_reclaims_24h' => (int) DB::table('workflow_job_histories')
                    ->whereIn('note', ['lease_expired', 'worker_offline'])
                    ->where('created_at', '>=', now()->subDay())->count(),
            ],
            // Index 상태 집계 — INDEXED/STALE/FAILED/PENDING (Runbook 지표 10)
            'search_index' => $countsBy('search_index_states'),
            'throughput_24h' => $throughput24h,
            'banners' => $this->deriveBanners(),
        ];
    }

    /** @return list<array<string, string>> */
    private function deriveBanners(): array
    {
        $banners = [];

        $offlineWorkers = DB::table('workflow_worker_agents')->where('status', 'OFFLINE')->count();
        if ($offlineWorkers > 0) {
            $banners[] = [
                'severity' => 'HIGH',
                'event_type' => 'worker_offline',
                'message' => "{$offlineWorkers}개 Worker가 OFFLINE 상태입니다.",
                'action_link' => '/admin/workflows/workers',
            ];
        }

        $failedRequired = DB::table('workflow_jobs')
            ->where('status', 'FAILED')->where('is_required', true)->count();
        if ($failedRequired > 0) {
            $banners[] = [
                'severity' => 'HIGH',
                'event_type' => 'required_job_failed',
                'message' => "필수 작업 {$failedRequired}건이 최종 실패 상태입니다.",
                'action_link' => '/admin/workflows/jobs?status=FAILED',
            ];
        }

        return $banners;
    }
}
