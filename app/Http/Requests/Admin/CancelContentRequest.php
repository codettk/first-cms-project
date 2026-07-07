<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CancelContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('workflow.cancel');
    }

    public function rules(): array
    {
        return ['reason' => 'required|string|max:1000'];
    }
}
