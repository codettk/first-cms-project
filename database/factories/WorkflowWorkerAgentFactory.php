<?php

namespace Database\Factories;

use App\Enums\WorkerStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowWorkerAgentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'worker_name' => 'worker-'.fake()->unique()->numberBetween(1, 99999),
            'worker_type' => 'GENERAL',
            'supported_job_types' => ['TM', 'VERIFY', 'MA', 'PUBLISH', 'CLEANUP'],
            'hostname' => fake()->domainWord(),
            'ip_address' => fake()->ipv4(),
            'status' => WorkerStatus::Offline,
            'max_concurrency' => 1,
            'gpu_available' => false,
        ];
    }

    public function online(): static
    {
        return $this->state(['status' => WorkerStatus::Online, 'last_heartbeat_at' => now()]);
    }

    public function transcode(): static
    {
        return $this->state([
            'worker_type' => 'TRANSCODE',
            'supported_job_types' => ['TC', 'CA'],
        ]);
    }
}
