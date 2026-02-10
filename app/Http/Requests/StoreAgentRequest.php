<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'config' => ['nullable', 'array'],
            'status' => ['nullable', 'in:active,inactive,error'],
            'ai_backend' => ['nullable', 'string', 'in:ollama,anthropic,openai,huggingface,vllm-gpu,vllm-cpu,vllm'],
            'model_config' => ['nullable', 'array'],
            'model_config.model' => ['nullable', 'string', 'max:255'],
            'model_config.temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'model_config.max_tokens' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'model_config.top_p' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'model_config.top_k' => ['nullable', 'integer', 'min:1'],
            'model_config.context_length' => ['nullable', 'integer', 'min:1024', 'max:1000000'],
            'model_config.timeout' => ['nullable', 'integer', 'min:10', 'max:3600'],
            'tool_ids' => ['nullable', 'array'],
            'tool_ids.*' => [
                'integer',
                Rule::exists('tools', 'id')->where('user_id', $this->user()?->id),
            ],
            'system_prompt_ids' => ['nullable', 'array'],
            'system_prompt_ids.*' => ['integer', 'exists:system_prompts,id'],
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
            'name.required' => 'The agent name is required.',
            'name.max' => 'The agent name must not exceed 255 characters.',
            'status.in' => 'The status must be one of: active, inactive, or error.',
            'ai_backend.in' => 'The AI backend must be one of: ollama, anthropic, openai, huggingface, or vllm.',
            'tool_ids.*.exists' => 'One or more selected tools do not exist or do not belong to you.',
            'system_prompt_ids.*.exists' => 'One or more selected system prompts do not exist.',
        ];
    }
}
