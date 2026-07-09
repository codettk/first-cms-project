<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseJobLockRequest extends FormRequest
{
    public function authorize(): bool
    {
        // HIGH — hasDirectPermission으로만 인정 (Gate 정의가 강제)
        return $this->user()->can('workflow.release_lock');
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:1000',
            'target_status' => 'nullable|string|in:RETRY,FAILED',
        ];
    }
}
