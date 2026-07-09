<?php

namespace Database\Factories;

use App\Enums\JobType;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowTemplateStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'template_id' => WorkflowTemplate::factory(),
            'job_type' => JobType::Tm,
            'step_order' => 1,
            'is_required' => true,
            'max_retry' => 3,
            'timeout_sec' => 600,
            'depends_on' => null,
            'config' => null,
        ];
    }
}
