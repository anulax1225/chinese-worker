# AI Backends

Chinese Worker supports multiple AI backends through a unified interface. This guide covers configuring and using each supported backend.

## Overview

| Backend | Type | Streaming | Function Calling | Vision | Model Management |
|---------|------|-----------|------------------|--------|------------------|
| **Ollama** | Local | Yes | Yes | Yes | Yes |
| **Anthropic Claude** | Cloud | Yes | Yes | No* | No |
| **OpenAI** | Cloud | Yes | Yes | Yes | No |

\* Claude supports vision but it's not yet implemented in Chinese Worker.

## Backend Selection

### Default Backend

Set in `.env`:

```env
AI_BACKEND=ollama
```

Options: `ollama`, `claude`, `openai`

### Per-Agent Override

Each agent can use a different backend:

```php
// Via API
POST /api/v1/agents
{
    "name": "My Agent",
    "ai_backend": "claude"
}
```

Or in the web UI when creating/editing an agent.

## Ollama

Ollama is the default and recommended backend for self-hosted deployments. It runs LLMs locally without API costs.

### Installation

**Linux:**
```bash
curl -fsSL https://ollama.com/install.sh | sh
```

**Docker:**
```bash
docker run -d -v ollama:/root/.ollama -p 11434:11434 ollama/ollama
```

**With Sail (development):**
Ollama is included in the Sail configuration automatically.

### Configuration

```env
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=llama3.1
OLLAMA_TIMEOUT=120
```

For Sail, use `http://ollama:11434` as the base URL.

### Model Management

#### Pull Models

```bash
# CLI
ollama pull llama3.1
ollama pull qwen2.5
ollama pull mistral

# With Sail
./vendor/bin/sail exec ollama ollama pull llama3.1

# Via API
POST /api/v1/ai-backends/ollama/models/pull
{
    "model": "llama3.1"
}
```

#### List Models

```bash
ollama list

# Via API
GET /api/v1/ai-backends/ollama/models
```

#### Delete Models

```bash
ollama rm model-name

# Via API
DELETE /api/v1/ai-backends/ollama/models/llama3.1
```

### Supported Models

| Model | Size | Context | Notes |
|-------|------|---------|-------|
| `llama3.1` | 8B/70B/405B | 128K | Meta's flagship model |
| `llama3.2` | 1B/3B | 128K | Smaller, faster |
| `qwen2.5` | 0.5B-72B | 128K | Alibaba's multilingual model |
| `mistral` | 7B | 32K | Fast, efficient |
| `deepseek-r1` | 7B-671B | 64K | Reasoning-focused |
| `codellama` | 7B/13B/34B | 16K | Code-specialized |
| `llava` | 7B/13B/34B | 4K | Vision-capable |

See https://ollama.com/library for the full list.

### GPU Acceleration

Ollama automatically uses:
- **NVIDIA GPU** with CUDA
- **AMD GPU** with ROCm
- **Apple Silicon** with Metal

For NVIDIA, ensure drivers and CUDA toolkit are installed:

```bash
# Check GPU
nvidia-smi

# Install CUDA toolkit
sudo apt install nvidia-cuda-toolkit
```

### Model Configuration

Agent-level model config overrides:

```json
{
    "model": "qwen2.5:14b",
    "temperature": 0.7,
    "maxTokens": 4096,
    "contextLength": 8192,
    "topP": 0.9,
    "topK": 40
}
```

**Ollama-specific parameters:**

| Parameter | Range | Default | Description |
|-----------|-------|---------|-------------|
| `temperature` | 0-2 | 0.7 | Randomness of output |
| `maxTokens` | 1-∞ | 4096 | Maximum response tokens |
| `contextLength` | 1-128K | 4096 | Context window size (`num_ctx`) |
| `topP` | 0-1 | 0.9 | Nucleus sampling |
| `topK` | 1-100 | 40 | Top-k sampling |

### Health Check

```bash
# Check Ollama is running
curl http://localhost:11434/api/tags

# Test generation
curl http://localhost:11434/api/generate -d '{
    "model": "llama3.1",
    "prompt": "Hello!",
    "stream": false
}'
```

## Anthropic Claude

Claude is Anthropic's flagship AI model, known for its reasoning capabilities and safety.

### Getting an API Key

1. Go to https://console.anthropic.com/
2. Create an account
3. Navigate to API Keys
4. Create a new key

### Configuration

```env
AI_BACKEND=claude
ANTHROPIC_API_KEY=sk-ant-api03-...
ANTHROPIC_MODEL=claude-sonnet-4-5-20250929
```

### Available Models

| Model | Context | Notes |
|-------|---------|-------|
| `claude-sonnet-4-5-20250929` | 200K | Latest, recommended |
| `claude-3-5-sonnet-20240620` | 200K | Previous generation |
| `claude-3-opus-20240229` | 200K | Highest capability |
| `claude-3-haiku-20240307` | 200K | Fastest, cheapest |

### Model Configuration

```json
{
    "model": "claude-3-haiku-20240307",
    "temperature": 0.7,
    "maxTokens": 4096
}
```

**Claude-specific parameters:**

| Parameter | Range | Default | Description |
|-----------|-------|---------|-------------|
| `temperature` | 0-1 | 0.7 | Randomness |
| `maxTokens` | 1-4096 | 4096 | Maximum output tokens |
| `topP` | 0-1 | 0.9 | Nucleus sampling |

### Pricing

Claude uses pay-per-token pricing. Check https://anthropic.com/pricing for current rates.

Example (approximate):
- Claude 3 Sonnet: $3/1M input, $15/1M output
- Claude 3 Haiku: $0.25/1M input, $1.25/1M output

## OpenAI

OpenAI provides GPT-4 and other models via their API.

### Getting an API Key

1. Go to https://platform.openai.com/
2. Create an account
3. Navigate to API Keys
4. Create a new secret key

### Configuration

```env
AI_BACKEND=openai
OPENAI_API_KEY=sk-proj-...
OPENAI_MODEL=gpt-4
```

### Available Models

| Model | Context | Notes |
|-------|---------|-------|
| `gpt-4` | 8K | High capability |
| `gpt-4-turbo` | 128K | Faster GPT-4 |
| `gpt-4o` | 128K | Latest, multimodal |
| `gpt-3.5-turbo` | 16K | Fast, economical |

### Model Configuration

```json
{
    "model": "gpt-4-turbo",
    "temperature": 0.7,
    "maxTokens": 4096
}
```

**OpenAI-specific parameters:**

| Parameter | Range | Default | Description |
|-----------|-------|---------|-------------|
| `temperature` | 0-2 | 0.7 | Randomness |
| `maxTokens` | 1-4096 | 4096 | Maximum output tokens |
| `topP` | 0-1 | 0.9 | Nucleus sampling |
| `frequencyPenalty` | -2 to 2 | 0 | Penalize frequent tokens |
| `presencePenalty` | -2 to 2 | 0 | Encourage new topics |

### Pricing

OpenAI uses pay-per-token pricing. Check https://openai.com/pricing for current rates.

## Configuration Normalization

Chinese Worker normalizes configuration across backends using `ModelConfigNormalizer`:

1. **Driver defaults** are applied first
2. **Global config** from `config/ai.php` is merged
3. **Agent overrides** from `agents.model_config` are applied
4. **Model limits** are enforced (context length clamped to model max)
5. **Unsupported parameters** are filtered per backend

### Example

```php
// Agent config
{
    "temperature": 0.5,
    "contextLength": 1000000  // Too large
}

// After normalization for llama3.1 (128K context)
{
    "model": "llama3.1",
    "temperature": 0.5,
    "contextLength": 131072,  // Clamped to model max
    "maxTokens": 4096,
    "timeout": 120,
    "validationWarnings": ["contextLength clamped from 1000000 to 131072"]
}
```

## Switching Backends

### At Runtime (per agent)

1. Edit the agent
2. Change the "AI Backend" dropdown
3. Save

The agent will immediately use the new backend for new conversations.

### Globally

1. Update `.env`:
   ```env
   AI_BACKEND=claude
   ```
2. Clear config cache:
   ```bash
   php artisan config:cache
   ```

## Fallback Strategy

Currently, Chinese Worker doesn't support automatic fallback between backends. If the configured backend fails, the job will fail.

For high availability:
1. Use a reliable backend (Ollama with sufficient resources, or cloud APIs)
2. Monitor job failures via Horizon
3. Consider running multiple instances with different backends

## Testing Backends

### Via API

```bash
# List available backends
curl -X GET /api/v1/ai-backends

# Check backend status
curl -X GET /api/v1/ai-backends/ollama

# List models
curl -X GET /api/v1/ai-backends/ollama/models
```

### Via Tinker

```bash
php artisan tinker

>>> $manager = app(\App\Services\AIBackendManager::class);
>>> $backend = $manager->driver('ollama');
>>> $backend->listModels();
```

## Best Practices

1. **Development:** Use Ollama with small models (llama3.2:1b, qwen2.5:0.5b)
2. **Production (cost-sensitive):** Use Ollama with appropriate hardware
3. **Production (performance-focused):** Use Claude or GPT-4 with caching
4. **Mixed workloads:** Use per-agent backend selection

## Troubleshooting

### Ollama Not Responding

```bash
# Check status
systemctl status ollama

# Check logs
journalctl -u ollama -f

# Test connection
curl http://localhost:11434/api/tags
```

### API Key Errors

```bash
# Verify key is set
php artisan tinker
>>> config('ai.backends.claude.api_key')

# Test API directly
curl https://api.anthropic.com/v1/messages \
  -H "x-api-key: $ANTHROPIC_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -d '{"model": "claude-3-haiku-20240307", "max_tokens": 10, "messages": [{"role": "user", "content": "Hi"}]}'
```

### Timeouts

Increase timeout in `.env`:

```env
OLLAMA_TIMEOUT=300
```

Or per-agent in model config:

```json
{
    "timeout": 300
}
```

## Next Steps

- [Search & WebFetch](search-and-webfetch.md) - Web integration
- [Queues & Jobs](queues-and-jobs.md) - Background processing
- [Configuration](configuration.md) - Full configuration reference
