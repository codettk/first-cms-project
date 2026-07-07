<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReprocessContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('workflow.reprocess');
    }

    public function rules(): array
    {
        return [
            'mode' => 'required|string|in:failed_from,full',
            'reason' => 'required|string|max:1000',
            'priority' => 'nullable|integer|min:0|max:1000',
            'keep_existing_renditions' => 'boolean',
            'requested_job_type' => 'nullable|string|in:TM,VERIFY,MA,TC,IMAGE_TC,AUDIO_TC,CA,DOC_PREVIEW,OCR,TEXT_EXTRACT,INDEX,PUBLISH,CLEANUP',
        ];
    }
}
