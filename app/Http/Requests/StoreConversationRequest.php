<?php

namespace App\Http\Requests;

use App\Models\Agent;
use Illuminate\Foundation\Http\FormRequest;

class StoreConversationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $agent = $this->route('agent');

        return $agent instanceof Agent && $this->user()->can('view', $agent);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'client_type' => ['required', 'string'],
            'client_tool_schemas' => ['nullable', 'array'],
            'client_tool_schemas.*.name' => ['required_with:client_tool_schemas', 'string'],
            'client_tool_schemas.*.description' => ['required_with:client_tool_schemas', 'string'],
            'client_tool_schemas.*.parameters' => ['required_with:client_tool_schemas', 'array'],
        ];
    }
}
