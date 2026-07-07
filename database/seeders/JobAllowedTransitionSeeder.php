<?php

namespace Database\Seeders;

use App\Enums\JobStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class JobAllowedTransitionSeeder extends Seeder
{
    /**
     * workflow_job_allowed_transitions seed — Migration Seed Spec §10.1.
     * JobStatus::transitionMap()을 단일 원천으로 사용해 enum ↔ seed 불일치를 구조적으로 차단한다.
     */
    public function run(): void
    {
        $rows = [];
        foreach (JobStatus::transitionMap() as $from => $targets) {
            foreach ($targets as $to) {
                $rows[] = ['from_status' => $from, 'to_status' => $to];
            }
        }

        DB::table('workflow_job_allowed_transitions')->upsert($rows, ['from_status', 'to_status']);
    }
}
