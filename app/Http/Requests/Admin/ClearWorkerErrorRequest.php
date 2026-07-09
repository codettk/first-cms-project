<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ClearWorkerErrorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('workflow.worker.manage');
    }

    public function rules(): array
    {
        return ['reason' => 'required|string|max:1000'];
    }
}
