<?php

namespace App\Http\Requests;

use App\DTOs\GenerateRequest;
use App\Models\Agent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgentGenerateRequest extends FormRequest
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
            'prompt' => ['required', 'string', 'min:1'],
            'suffix' => ['nullable', 'string'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string'],
            'format' => ['nullable'],
            'system' => ['nullable', 'string'],
            'think' => ['nullable', Rule::in([true, false, 'high', 'medium', 'low'])],
            'raw' => ['nullable', 'boolean'],
            'keep_alive' => ['nullable'],
            'max_tokens' => ['nullable', 'integer', 'min:1'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'top_p' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'top_k' => ['nullable', 'integer', 'min:1'],
            'min_p' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'seed' => ['nullable', 'integer'],
            'stop' => ['nullable'],
            'context_length' => ['nullable', 'integer', 'min:1'],
            'logprobs' => ['nullable', 'boolean'],
            'top_logprobs' => ['nullable', 'integer', 'min:1'],
            'stream' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Convert validated data to a GenerateRequest DTO.
     */
    public function toGenerateRequest(): GenerateRequest
    {
        $data = $this->validated();

        return new GenerateRequest(
            prompt: $data['prompt'],
            suffix: $data['suffix'] ?? null,
            images: $data['images'] ?? null,
            format: $data['format'] ?? null,
            system: $data['system'] ?? null,
            think: $data['think'] ?? null,
            raw: $data['raw'] ?? false,
            keepAlive: $data['keep_alive'] ?? null,
            maxTokens: $data['max_tokens'] ?? null,
            temperature: $data['temperature'] ?? null,
            topP: $data['top_p'] ?? null,
            topK: $data['top_k'] ?? null,
            minP: $data['min_p'] ?? null,
            seed: $data['seed'] ?? null,
            stop: $data['stop'] ?? null,
            contextLength: $data['context_length'] ?? null,
            logprobs: $data['logprobs'] ?? null,
            topLogprobs: $data['top_logprobs'] ?? null,
        );
    }

    /**
     * Check if streaming is requested.
     */
    public function wantsStream(): bool
    {
        return $this->boolean('stream', false);
    }
}
