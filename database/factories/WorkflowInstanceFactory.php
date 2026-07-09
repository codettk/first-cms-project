<?php

namespace Database\Factories;

use App\Enums\InstanceStatus;
use App\Models\Content;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowInstanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content_id' => Content::factory(),
            'template_id' => WorkflowTemplate::factory(),
            'status' => InstanceStatus::Running,
            'started_at' => now(),
        ];
    }

    public function success(): static
    {
        return $this->state(['status' => InstanceStatus::Success, 'finished_at' => now()]);
    }

    public function failed(): static
    {
        return $this->state(['status' => InstanceStatus::Failed, 'finished_at' => now()]);
    }
}
