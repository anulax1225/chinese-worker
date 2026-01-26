<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteAgentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payload' => ['required', 'array'],
            'file_ids' => ['nullable', 'array'],
            'file_ids.*' => ['integer', 'exists:files,id'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:10'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payload.required' => 'The execution payload is required.',
            'payload.array' => 'The payload must be a valid array.',
            'file_ids.*.exists' => 'One or more selected files do not exist.',
            'priority.min' => 'The priority must be at least 0.',
            'priority.max' => 'The priority must not exceed 10.',
            'scheduled_at.after' => 'The scheduled time must be in the future.',
        ];
    }
}
