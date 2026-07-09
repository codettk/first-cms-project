<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJobPriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('workflow.retry');
    }

    public function rules(): array
    {
        return [
            'priority' => 'required|integer|min:0|max:1000',
            'reason' => 'nullable|string|max:1000',
        ];
    }
}
