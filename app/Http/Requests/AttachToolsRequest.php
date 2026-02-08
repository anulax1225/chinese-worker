<?php

namespace App\Http\Requests;

use App\Models\Agent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachToolsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $agent = $this->route('agent');

        return $agent instanceof Agent && $this->user()->can('update', $agent);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tool_ids' => ['required', 'array'],
            'tool_ids.*' => [
                'integer',
                Rule::exists('tools', 'id')->where('user_id', $this->user()->id),
            ],
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
            'tool_ids.*.exists' => 'One or more selected tools do not exist or do not belong to you.',
        ];
    }
}
