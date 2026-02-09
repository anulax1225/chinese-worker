# Hugging Face Backend Implementation Plan

## 1. Overview

This plan covers adding **Hugging Face Inference Providers** as a fourth AI backend to Chinese Worker. The HF Inference Providers API is **OpenAI-compatible**, meaning it uses the same `POST /v1/chat/completions` schema for requests and responses. This significantly simplifies implementation — the backend is structurally similar to your existing `OpenAIBackend`, with HF-specific authentication and model routing.

### Key Design Decision

Hugging Face Inference Providers is a **routing layer** over 15+ infrastructure providers (Cerebras, Together, Fireworks, Nebius, SambaNova, etc.). You send requests to `https://router.huggingface.co/v1`, and HF routes them to the actual provider. This gives access to hundreds of models (Llama, Qwen, DeepSeek, Mistral, etc.) through a single API key and endpoint.

### Capabilities Summary

| Capability | Supported | Notes |
|---|---|---|
| **Streaming** | Yes | SSE via `stream: true`, OpenAI-compatible delta format |
| **Function Calling** | Yes | OpenAI `tools` schema; model must support it |
| **Vision** | Yes | VLM models accept `image_url` content blocks |
| **Model Management** | No | No pull/delete — models are serverless |
| **Provider Selection** | Yes | Append `:provider` or `:fastest`/`:cheapest` to model ID |

---

## 2. API Specification

### 2.1 Authentication

| Item | Value |
|---|---|
| **Token type** | Hugging Face User Access Token (fine-grained) |
| **Required permission** | "Make calls to Inference Providers" |
| **Header** | `Authorization: Bearer hf_xxxxxxxxxx` |
| **Token prefix** | `hf_` |
| **Create at** | https://huggingface.co/settings/tokens |

### 2.2 Base URL

```
https://router.huggingface.co/v1
```

This is the unified router endpoint. All chat completion requests go to:

```
POST https://router.huggingface.co/v1/chat/completions
```

### 2.3 Request Schema (Chat Completion)

```json
{
    "model": "meta-llama/Llama-3.1-8B-Instruct",
    "messages": [
        {"role": "system", "content": "You are a helpful assistant."},
        {"role": "user", "content": "Hello!"}
    ],
    "stream": true,
    "temperature": 0.7,
    "max_tokens": 4096,
    "top_p": 0.9,
    "frequency_penalty": 0.0,
    "presence_penalty": 0.0,
    "stop": ["<|end|>"],
    "tools": [...],
    "tool_choice": "auto"
}
```

**Supported request parameters:**

| Parameter | Type | Range | Default | Description |
|---|---|---|---|---|
| `model` | string | — | required | HF model ID, optionally with `:provider` suffix |
| `messages` | array | — | required | Conversation messages |
| `stream` | bool | — | `false` | Enable SSE streaming |
| `temperature` | float | 0–2 | 0.7 | Sampling temperature |
| `max_tokens` | int | 1–∞ | varies | Max output tokens |
| `top_p` | float | 0–1 | 0.9 | Nucleus sampling |
| `frequency_penalty` | float | -2 to 2 | 0 | Penalize frequent tokens |
| `presence_penalty` | float | -2 to 2 | 0 | Encourage new topics |
| `stop` | array | up to 4 | — | Stop sequences |
| `seed` | int | — | — | Deterministic generation |
| `tools` | array | — | — | Tool/function definitions |
| `tool_choice` | string/object | — | `"auto"` | Tool selection strategy |
| `response_format` | object | — | — | JSON mode / JSON schema |

### 2.4 Model ID Format

HF uses full model IDs from the Hub, with optional provider routing:

```
# Auto-route (HF picks best provider based on user preferences)
meta-llama/Llama-3.1-8B-Instruct

# Specific provider
meta-llama/Llama-3.1-8B-Instruct:cerebras
deepseek-ai/DeepSeek-R1:nebius

# Routing policies
openai/gpt-oss-120b:fastest    # Highest throughput provider
openai/gpt-oss-120b:cheapest   # Lowest cost provider
```

### 2.5 Non-Streaming Response

```json
{
    "id": "chatcmpl-abc123",
    "object": "chat.completion",
    "created": 1234567890,
    "model": "meta-llama/Llama-3.1-8B-Instruct",
    "system_fingerprint": "...",
    "choices": [
        {
            "index": 0,
            "message": {
                "role": "assistant",
                "content": "Hello! How can I help you?",
                "tool_calls": null
            },
            "finish_reason": "stop",
            "logprobs": null
        }
    ],
    "usage": {
        "prompt_tokens": 25,
        "completion_tokens": 8,
        "total_tokens": 33
    }
}
```

### 2.6 Streaming Response (SSE)

Each chunk follows the OpenAI delta format:

```
data: {"id":"chatcmpl-abc","choices":[{"index":0,"delta":{"role":"assistant"},"finish_reason":null}]}

data: {"id":"chatcmpl-abc","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}

data: {"id":"chatcmpl-abc","choices":[{"index":0,"delta":{"content":"!"},"finish_reason":null}]}

data: {"id":"chatcmpl-abc","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":25,"completion_tokens":2,"total_tokens":27}}

data: [DONE]
```

### 2.7 Tool Calls in Response

**Non-streaming:**
```json
{
    "choices": [{
        "message": {
            "role": "assistant",
            "tool_calls": [
                {
                    "id": "call_abc123",
                    "type": "function",
                    "function": {
                        "name": "get_current_weather",
                        "arguments": "{\"location\": \"San Francisco\"}"
                    }
                }
            ]
        },
        "finish_reason": "tool_calls"
    }]
}
```

**Streaming tool calls (chunked):**
```
data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_abc","type":"function","function":{"name":"get_current_weather","arguments":""}}]}}]}

data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"lo"}}]}}]}

data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"cation\": \"SF\"}"}}]}}]}

data: {"choices":[{"delta":{},"finish_reason":"tool_calls"}]}
```

> **Critical:** Unlike Ollama where tool call arguments come as a parsed object, HF returns `arguments` as a **JSON string** that must be decoded with `json_decode()`.

### 2.8 Tool Result Message Format

When sending tool results back in the conversation:

```json
{
    "role": "tool",
    "tool_call_id": "call_abc123",
    "content": "{\"temperature\": \"22°C\", \"condition\": \"Sunny\"}"
}
```

### 2.9 Vision (VLM) Message Format

```json
{
    "role": "user",
    "content": [
        {"type": "text", "text": "Describe this image."},
        {
            "type": "image_url",
            "image_url": {
                "url": "https://example.com/image.jpg"
            }
        }
    ]
}
```

Supports both URLs and base64 data URIs (`data:image/jpeg;base64,...`).

---

## 3. Recommended Models

| Model | Context | Tool Calling | Vision | Notes |
|---|---|---|---|---|
| `meta-llama/Llama-3.1-8B-Instruct` | 128K | Yes | No | Good general-purpose, widely available |
| `meta-llama/Llama-3.1-70B-Instruct` | 128K | Yes | No | High capability |
| `Qwen/Qwen2.5-72B-Instruct` | 128K | Yes | No | Strong multilingual |
| `Qwen/Qwen2.5-Coder-32B-Instruct` | 128K | Yes | No | Code-specialized |
| `deepseek-ai/DeepSeek-R1` | 64K | Limited | No | Reasoning-focused |
| `mistralai/Mistral-7B-Instruct-v0.3` | 32K | Yes | No | Fast, efficient |
| `openai/gpt-oss-120b` | 128K | Yes | No | OpenAI's open model on HF |
| `google/gemma-2-2b-it` | 8K | Limited | No | Tiny, fast |
| `Qwen/Qwen3-Coder-480B-A35B-Instruct` | 128K | Yes | No | Latest coding model |

**Default recommendation:** `meta-llama/Llama-3.1-8B-Instruct` (widely supported across providers, good tool calling).

---

## 4. Implementation Plan

### 4.1 Environment Variables

Add to `.env.example` and `.env`:

```env
# Hugging Face Inference Providers
HUGGINGFACE_API_KEY=hf_xxxxxxxxxx
HUGGINGFACE_MODEL=meta-llama/Llama-3.1-8B-Instruct
HUGGINGFACE_BASE_URL=https://router.huggingface.co/v1
HUGGINGFACE_TIMEOUT=120
HUGGINGFACE_PROVIDER=              # Optional: cerebras, together, nebius, etc.
```

### 4.2 Configuration — `config/ai.php`

Add to the `backends` array:

```php
'huggingface' => [
    'api_key' => env('HUGGINGFACE_API_KEY'),
    'base_url' => env('HUGGINGFACE_BASE_URL', 'https://router.huggingface.co/v1'),
    'model' => env('HUGGINGFACE_MODEL', 'meta-llama/Llama-3.1-8B-Instruct'),
    'max_tokens' => 4096,
    'timeout' => env('HUGGINGFACE_TIMEOUT', 120),
    'provider' => env('HUGGINGFACE_PROVIDER'),  // optional routing
],
```

Update the `AI_BACKEND` enum validation to include `huggingface`.

### 4.3 New File: `app/Services/AI/HuggingFaceBackend.php`

Implements `AIBackendInterface`. Here's the full structural plan:

```
class HuggingFaceBackend implements AIBackendInterface
{
    // Properties
    protected Client $client;
    protected string $baseUrl;
    protected string $model;
    protected string $apiKey;
    protected int $timeout;
    protected ?string $provider;
    protected ?NormalizedModelConfig $normalizedConfig = null;

    // Constructor
    __construct(array $config)
        - Validate: api_key required, base_url valid URL
        - Store config values
        - Create Guzzle client with:
            - base_uri: $baseUrl
            - timeout: $timeout
            - headers: Content-Type, Accept, Authorization: Bearer $apiKey

    // AIBackendInterface methods

    withConfig(NormalizedModelConfig $config): static
        - Clone self
        - Override model, timeout from normalized config
        - Recreate Guzzle client with new timeout

    execute(Agent $agent, array $context): AIResponse
        - Build messages (buildMessages)
        - Build tools (from context or agent)
        - Convert tools to OpenAI format (convertToolsToOpenAIFormat)
        - Build payload (model, messages, tools, params)
        - POST /chat/completions
        - Parse non-streaming response (parseResponse)

    streamExecute(Agent $agent, array $context, callable $callback): AIResponse
        - Same setup as execute but stream: true
        - Parse SSE lines (data: {...})
        - Handle delta.content → callback('content', $text)
        - Handle delta.tool_calls → accumulate chunked arguments
        - Handle [DONE] → build final response
        - Handle finish_reason

    validateConfig(array $config): bool
        - Check api_key is set and starts with 'hf_'
        - Check base_url is valid URL (if provided)

    getCapabilities(): array
        - streaming: true
        - function_calling: true
        - vision: true
        - model_management: false
        - embeddings: false

    listModels(bool $detailed = false): array
        - GET /models (OpenAI-compatible endpoint)
        - Map to AIModel DTOs
        - NOTE: This endpoint may have limited results;
          alternatively return a curated static list

    supportsModelManagement(): bool → false
    pullModel(): void → throw RuntimeException('not supported')
    deleteModel(): void → throw RuntimeException('not supported')
    showModel(): AIModel → throw RuntimeException('not supported')
    countTokens(string $text): int → character-based estimate (chars/4)
    getContextLimit(): int → from normalizedConfig or default 4096
    disconnect(): void → recreate client with Connection: close
    formatMessage(ChatMessage $message): array → OpenAI message format
    parseToolCall(array $data): ToolCall → parse OpenAI tool_call format

    // Private helper methods

    buildMessages(Agent $agent, array $context): array<ChatMessage>
        - Same pattern as OllamaBackend
        - System prompt from context or agent
        - Conversation history
        - Current user input with optional images

    buildSystemPrompt(Agent $agent, array $context): string
        - Same pattern as OllamaBackend

    buildPayload(array $messages, array $tools): array
        - Construct the request body
        - Apply model config (temperature, top_p, max_tokens, etc.)
        - Apply provider suffix to model if configured
        - Include frequency_penalty, presence_penalty if set

    getModelWithProvider(): string
        - If $this->provider is set, return "{$this->model}:{$this->provider}"
        - Else return $this->model

    parseResponse(array $data): AIResponse
        - Extract choices[0].message
        - Map content, tool_calls, finish_reason
        - Map usage to tokensUsed
        - Return AIResponse

    parseStreamedToolCalls(array $accumulatedCalls): array<ToolCall>
        - Merge chunked function.arguments strings
        - json_decode the full arguments string
        - Return ToolCall DTOs

    buildAIResponse(string $content, array $data, array $toolCalls, ?string $thinking): AIResponse
        - Same pattern as OllamaBackend
        - Map finish_reason: "stop" | "tool_calls" | "length"
        - Populate metadata with usage stats

    convertToolsToOpenAIFormat(array $tools): array
        - Same as OllamaBackend's convertToolsToOllamaFormat
          (both use OpenAI's {type: "function", function: {...}} schema)
```

### 4.4 Key Implementation Differences from Ollama

| Aspect | Ollama | Hugging Face |
|---|---|---|
| **Endpoint** | `/api/chat` | `/v1/chat/completions` |
| **Auth** | None | `Bearer hf_xxx` header |
| **Stream format** | NDJSON (one JSON per line) | SSE (`data: {...}` lines) |
| **Tool args** | Parsed object | JSON string (must decode) |
| **Tool call IDs** | Often missing (use `uniqid`) | Always present |
| **Finish signal** | `"done": true` in JSON | `data: [DONE]` line |
| **Token usage** | `eval_count` / `prompt_eval_count` | `usage.completion_tokens` / `usage.prompt_tokens` |
| **Model management** | Full (pull/delete/show) | None (serverless) |
| **Vision format** | `images: [base64]` on message | `content: [{type: "image_url", ...}]` |
| **Thinking** | `message.thinking` field | Not standard (model-dependent) |

### 4.5 SSE Stream Parsing

The HF API uses standard SSE format, not NDJSON like Ollama. The parsing logic needs to handle:

```
data: {"id":"...","choices":[{"delta":{"content":"Hello"}}]}
data: {"id":"...","choices":[{"delta":{"content":" world"}}]}
data: [DONE]
```

Pseudocode for `streamExecute`:

```php
$buffer = '';
$fullContent = '';
$toolCallAccumulator = []; // index => {id, name, arguments_parts[]}

while (!$body->eof()) {
    $buffer .= $body->read(8192);
    
    // Process complete lines
    while (($pos = strpos($buffer, "\n")) !== false) {
        $line = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);
        $line = trim($line);
        
        if ($line === '') continue;
        if ($line === 'data: [DONE]') break 2;
        if (!str_starts_with($line, 'data: ')) continue;
        
        $json = substr($line, 6); // Remove "data: " prefix
        $data = json_decode($json, true);
        if (!$data) continue;
        
        $choice = $data['choices'][0] ?? [];
        $delta = $choice['delta'] ?? [];
        
        // Content streaming
        if (isset($delta['content']) && $delta['content'] !== '') {
            $fullContent .= $delta['content'];
            $callback($delta['content'], 'content');
        }
        
        // Tool call streaming (chunked arguments)
        if (isset($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $tc) {
                $idx = $tc['index'];
                if (isset($tc['id'])) {
                    $toolCallAccumulator[$idx] = [
                        'id' => $tc['id'],
                        'name' => $tc['function']['name'] ?? '',
                        'arguments' => '',
                    ];
                }
                if (isset($tc['function']['arguments'])) {
                    $toolCallAccumulator[$idx]['arguments'] .= $tc['function']['arguments'];
                }
            }
        }
        
        // Check finish reason
        if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
            $finishReason = $choice['finish_reason'];
        }
    }
}

// Build tool calls from accumulated data
$toolCalls = array_map(function ($tc) {
    return new ToolCall(
        id: $tc['id'],
        name: $tc['name'],
        arguments: json_decode($tc['arguments'], true) ?? []
    );
}, $toolCallAccumulator);
```

### 4.6 Register in `AIBackendManager`

Add the `huggingface` driver to the manager:

```php
// In AIBackendManager or service provider

protected function createHuggingFaceDriver(): AIBackendInterface
{
    return new HuggingFaceBackend(
        config('ai.backends.huggingface')
    );
}
```

Ensure the manager's `driver()` method and `forAgent()` resolution handle `'huggingface'` as a valid driver name.

### 4.7 `ModelConfigNormalizer` Updates

Add `huggingface` driver defaults:

```php
'huggingface' => [
    'temperature' => 0.7,
    'maxTokens' => 4096,
    'topP' => 0.9,
    'frequencyPenalty' => 0.0,
    'presencePenalty' => 0.0,
    'timeout' => 120,
],
```

Add a model limits map (context lengths vary by model — use sensible defaults):

```php
'huggingface_model_limits' => [
    'meta-llama/Llama-3.1-8B-Instruct' => ['context' => 131072],
    'meta-llama/Llama-3.1-70B-Instruct' => ['context' => 131072],
    'Qwen/Qwen2.5-72B-Instruct' => ['context' => 131072],
    'mistralai/Mistral-7B-Instruct-v0.3' => ['context' => 32768],
    'deepseek-ai/DeepSeek-R1' => ['context' => 65536],
    'google/gemma-2-2b-it' => ['context' => 8192],
    // fallback default: 4096
],
```

Add a `toHuggingFaceOptions()` method to `NormalizedModelConfig`:

```php
public function toHuggingFaceOptions(): array
{
    return array_filter([
        'temperature' => $this->temperature,
        'max_tokens' => $this->maxTokens,
        'top_p' => $this->topP,
        'frequency_penalty' => $this->frequencyPenalty,
        'presence_penalty' => $this->presencePenalty,
    ], fn ($v) => $v !== null);
}
```

### 4.8 `NormalizedModelConfig` DTO Update

Add the optional `frequencyPenalty` and `presencePenalty` properties if not already present (they exist for OpenAI, so likely already there). Also add an optional `provider` property for HF routing.

### 4.9 `AIModel` DTO Update

Add a static factory method:

```php
public static function fromHuggingFace(array $data): self
{
    return new self(
        name: $data['id'] ?? $data['name'] ?? 'unknown',
        size: null,
        parameterSize: null,
        quantizationLevel: null,
        modifiedAt: null,
        details: [
            'provider' => 'huggingface',
            'owned_by' => $data['owned_by'] ?? null,
        ],
    );
}
```

### 4.10 Validation Updates

Update `StoreAgentRequest` / `UpdateAgentRequest`:

```php
'ai_backend' => ['required', Rule::in(['ollama', 'claude', 'openai', 'huggingface'])],
```

### 4.11 API Backend Controller Updates

The `AIBackendController` already handles generic backend operations. Ensure:

- `GET /api/v1/ai-backends` includes `huggingface` when configured
- `GET /api/v1/ai-backends/huggingface` returns status and capabilities
- `GET /api/v1/ai-backends/huggingface/models` returns a list (either from API or curated)
- Pull/delete endpoints return 404 or appropriate error for HF (not supported)

### 4.12 Frontend Updates

- Add "Hugging Face" to the AI backend dropdown in agent create/edit forms
- Add provider field (optional) — dropdown or text input for routing preference
- Update any model selection UI to handle HF model IDs (which use `org/model` format with `/`)

### 4.13 Documentation Updates

Add to `ai-backends.md`:

- New row in the overview table
- Full Hugging Face section with setup instructions
- Model recommendations
- Provider selection guide

Update the overview table:

| Backend | Type | Streaming | Function Calling | Vision | Model Management |
|---|---|---|---|---|---|
| **Hugging Face** | Cloud | Yes | Yes | Yes (VLM models) | No |

---

## 5. Migration & Database

### 5.1 Migration

Add `'huggingface'` to any enum/check constraints on `agents.ai_backend` if using database-level validation. If it's validated only at the application level (Form Requests), no migration is needed.

```php
// If there's a migration with enum constraint:
Schema::table('agents', function (Blueprint $table) {
    // Update check constraint to include 'huggingface'
});
```

### 5.2 Config Seeder (Optional)

If you have default agent seeds, add a HuggingFace example agent.

---

## 6. Pricing & Billing Considerations

Hugging Face Inference Providers pricing works differently from Anthropic/OpenAI:

| Aspect | Details |
|---|---|
| **Pricing model** | Pass-through to underlying provider (no HF markup) |
| **Free tier** | Monthly credits for all HF users ($2/month for PRO users) |
| **Billing** | Pay-as-you-go, billed through HF account |
| **Custom keys** | Can use provider's own API key (bypasses HF billing) |
| **Rate limits** | Varies by provider; no universal limit published |

Important: Unlike Anthropic/OpenAI with fixed per-token pricing, HF provider pricing varies. Some providers charge per token, others per compute-second. Document this for users.

---

## 7. Error Handling

Map HF API errors to your existing error handling:

| HTTP Status | HF Meaning | Chinese Worker Handling |
|---|---|---|
| 401 | Invalid/missing token | RuntimeException with "API key" message |
| 403 | Token lacks permissions | RuntimeException with permissions message |
| 404 | Model not found on provider | RuntimeException with model suggestion |
| 422 | Invalid request params | RuntimeException with validation details |
| 429 | Rate limited | RuntimeException; consider retry-after header |
| 500/502/503 | Provider error | RuntimeException; suggest trying different provider |

The HF API returns errors as:

```json
{
    "error": "Model meta-llama/Llama-3.1-8B-Instruct is not available for provider X"
}
```

Parse the `error` field for user-friendly messages.

---

## 8. Testing Plan

### 8.1 Unit Tests

Create `tests/Unit/Services/AI/HuggingFaceBackendTest.php`:

- `test_validate_config_requires_api_key`
- `test_validate_config_requires_valid_url`
- `test_get_capabilities_returns_correct_flags`
- `test_supports_model_management_returns_false`
- `test_format_message_standard`
- `test_format_message_with_images` (vision format)
- `test_format_message_with_tool_result`
- `test_parse_tool_call_decodes_json_string_arguments`
- `test_parse_response_maps_usage_correctly`
- `test_model_with_provider_suffix`
- `test_count_tokens_estimation`

### 8.2 Feature Tests

Create `tests/Feature/Api/V1/HuggingFaceBackendTest.php`:

- `test_list_backends_includes_huggingface`
- `test_create_agent_with_huggingface_backend`
- `test_conversation_with_huggingface_agent` (mock HTTP)
- `test_streaming_response_parsing` (mock SSE)
- `test_tool_call_parsing_from_stream`
- `test_model_config_normalization_for_huggingface`

### 8.3 Manual Integration Test

```bash
php artisan tinker

>>> $manager = app(\App\Services\AIBackendManager::class);
>>> $backend = $manager->driver('huggingface');
>>> $backend->getCapabilities();

# Test with a real request (needs valid API key)
>>> $agent = Agent::factory()->create(['ai_backend' => 'huggingface']);
>>> $response = $backend->execute($agent, [
...     'input' => 'Hello, what is 2+2?',
...     'messages' => [],
... ]);
```

---

## 9. File Checklist

| File | Action | Description |
|---|---|---|
| `app/Services/AI/HuggingFaceBackend.php` | **Create** | Main backend implementation |
| `config/ai.php` | **Edit** | Add `huggingface` backend config |
| `.env.example` | **Edit** | Add HF env vars |
| `app/Services/AI/AIBackendManager.php` | **Edit** | Register `huggingface` driver |
| `app/Services/AI/ModelConfigNormalizer.php` | **Edit** | Add HF defaults & model limits |
| `app/DTOs/NormalizedModelConfig.php` | **Edit** | Add `toHuggingFaceOptions()` |
| `app/DTOs/AIModel.php` | **Edit** | Add `fromHuggingFace()` factory |
| `app/Http/Requests/StoreAgentRequest.php` | **Edit** | Add `huggingface` to validation |
| `app/Http/Requests/UpdateAgentRequest.php` | **Edit** | Add `huggingface` to validation |
| `database/migrations/xxxx_add_huggingface_backend.php` | **Create** | Only if DB enum constraint exists |
| `tests/Unit/Services/AI/HuggingFaceBackendTest.php` | **Create** | Unit tests |
| `tests/Feature/Api/V1/HuggingFaceBackendTest.php` | **Create** | Feature tests |
| `docs/ai-backends.md` | **Edit** | Add HF documentation section |
| Frontend agent form components | **Edit** | Add HF to backend dropdown |

---

## 10. Implementation Order

1. **Config & env** — Add env vars and `config/ai.php` entry
2. **DTOs** — Update `NormalizedModelConfig`, `AIModel` with HF methods
3. **Backend class** — Implement `HuggingFaceBackend` (non-streaming first)
4. **Manager registration** — Wire up in `AIBackendManager`
5. **Normalizer** — Add HF defaults and model limits
6. **Streaming** — Implement `streamExecute` with SSE parsing
7. **Tool calls** — Handle chunked tool call accumulation in streams
8. **Vision** — Format image messages in OpenAI VLM format
9. **Validation** — Update request validators
10. **Frontend** — Add HF to agent UI dropdowns
11. **Tests** — Write unit and feature tests
12. **Documentation** — Update docs
13. **Migration** — If needed for DB constraints
