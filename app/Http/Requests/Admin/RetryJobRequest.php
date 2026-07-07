<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RetryJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('workflow.retry');
    }

    public function rules(): array
    {
        return [
            'reason' => 'nullable|string|max:1000',
            'reset_retry_count' => 'boolean',
            'priority' => 'nullable|integer|min:0|max:1000',
        ];
    }
}
