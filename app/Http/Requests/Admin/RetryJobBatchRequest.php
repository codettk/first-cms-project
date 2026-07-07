<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RetryJobBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('workflow.retry');
    }

    public function rules(): array
    {
        return [
            'job_ids' => 'required|array|min:1|max:100',
            'job_ids.*' => 'integer',
            'reason' => 'required|string|max:1000',
        ];
    }
}
