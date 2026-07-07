<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Models\Content;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowJobFactory extends Factory
{
    public function definition(): array
    {
        $content = Content::factory();

        return [
            'instance_id' => WorkflowInstance::factory()->for($content, 'content'),
            'content_id' => $content,
            'job_type' => JobType::Tm,
            'status' => JobStatus::Waiting,
            'is_required' => true,
            'priority' => 100,
            'retry_count' => 0,
            'max_retry' => 3,
            'timeout_sec' => 600,
        ];
    }

    public function ofType(JobType $type): static
    {
        return $this->state(['job_type' => $type]);
    }

    public function ready(): static
    {
        return $this->state(['status' => JobStatus::Ready]);
    }

    public function running(): static
    {
        return $this->state(['status' => JobStatus::Running, 'started_at' => now()]);
    }

    public function success(): static
    {
        return $this->state(['status' => JobStatus::Success, 'started_at' => now(), 'finished_at' => now()]);
    }

    public function failed(): static
    {
        return $this->state(['status' => JobStatus::Failed, 'started_at' => now(), 'finished_at' => now()]);
    }
}
