<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class EnableWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('workflow.worker.manage');
    }

    public function rules(): array
    {
        return ['reason' => 'nullable|string|max:1000'];
    }
}
