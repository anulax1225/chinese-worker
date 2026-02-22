<?php

namespace App\Http\Requests;

use App\Models\Agent;
use Illuminate\Foundation\Http\FormRequest;

class GhostConversationRequest extends FormRequest
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
            'content' => ['required_without:tool_result', 'nullable', 'string'],
            'messages' => ['nullable', 'array'],
            'messages.*.role' => ['required_with:messages', 'string', 'in:user,assistant,tool,system'],
            'messages.*.content' => ['required_with:messages', 'string'],
            'messages.*.tool_calls' => ['nullable', 'array'],
            'messages.*.tool_call_id' => ['nullable', 'string'],
            'messages.*.thinking' => ['nullable', 'string'],
            'messages.*.name' => ['nullable', 'string'],
            'tool_result' => ['nullable', 'array'],
            'tool_result.call_id' => ['required_with:tool_result', 'string'],
            'tool_result.success' => ['required_with:tool_result', 'boolean'],
            'tool_result.output' => ['nullable', 'string'],
            'tool_result.error' => ['nullable', 'string'],
            'client_tool_schemas' => ['nullable', 'array'],
            'client_tool_schemas.*.name' => ['required_with:client_tool_schemas', 'string'],
            'client_tool_schemas.*.description' => ['required_with:client_tool_schemas', 'string'],
            'client_tool_schemas.*.parameters' => ['required_with:client_tool_schemas', 'array'],
            'max_turns' => ['nullable', 'integer', 'min:1', 'max:50'],
            'context' => ['nullable', 'array'],
            'context.*' => ['nullable', 'string'],
        ];
    }
}
