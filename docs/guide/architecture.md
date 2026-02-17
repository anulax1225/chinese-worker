# Architecture

This document describes the high-level architecture of Chinese Worker, including its main components, services, and data flow.

## System Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              Clients                                    │
│                   Web UI │ CLI │ External APIs                          │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                           Laravel Application                           │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────┐  │
│  │   Web Routes    │  │   API Routes    │  │   WebSocket (Reverb)    │  │
│  │   (Inertia)     │  │   (Sanctum)     │  │   (Broadcasting)        │  │
│  └─────────────────┘  └─────────────────┘  └─────────────────────────┘  │
│                                    │                                    │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │                         Services Layer                          │   │
│  │  ┌──────────────┐ ┌──────────────┐ ┌──────────────────────────┐ │   │
│  │  │ Conversation │ │   Prompt     │ │    AIBackendManager      │ │   │
│  │  │   Service    │ │  Assembler   │ │  (Ollama/Claude/OpenAI)  │ │   │
│  │  └──────────────┘ └──────────────┘ └──────────────────────────┘ │   │
│  │  ┌──────────────┐ ┌──────────────┐ ┌──────────────────────────┐ │   │
│  │  │   Search     │ │   WebFetch   │ │      Tool Service        │ │   │
│  │  │   Service    │ │   Service    │ │   (Schema Registry)      │ │   │
│  │  └──────────────┘ └──────────────┘ └──────────────────────────┘ │   │
│  └─────────────────────────────────────────────────────────────────┘   │
│                                    │                                    │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │                          Queue Jobs                              │   │
│  │  ProcessConversationTurn │ PullModelJob │ CleanupTempFilesJob   │   │
│  └─────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
        ┌───────────────────────────┼───────────────────────────┐
        ▼                           ▼                           ▼
┌───────────────┐          ┌───────────────┐          ┌───────────────┐
│  PostgreSQL   │          │    Redis      │          │    Ollama     │
│  + pgvector   │          │ (Cache/Queue) │          │  (Local LLM)  │
└───────────────┘          └───────────────┘          └───────────────┘
                                                              │
                                    ┌─────────────────────────┼─────────┐
                                    ▼                         ▼         ▼
                           ┌───────────────┐          ┌──────────┐ ┌────────┐
                           │   SearXNG     │          │ Anthropic│ │ OpenAI │
                           │  (Search)     │          │   API    │ │  API   │
                           └───────────────┘          └──────────┘ └────────┘
```

## Core Components

### Models

| Model | Purpose | Key Relationships |
|-------|---------|-------------------|
| **User** | Authentication and ownership | Has many: Agents, Tools, Files, Conversations |
| **Agent** | AI agent configuration | Belongs to: User. Has many: Conversations, Tools, SystemPrompts |
| **Conversation** | Chat session state | Belongs to: User, Agent. Stores: messages, status, tool requests |
| **Tool** | Reusable tool definition | Belongs to: User. Many-to-many: Agents |
| **SystemPrompt** | Prompt template | Many-to-many: Agents (with pivot: order, variable_overrides) |
| **Todo** | Task tracking | Belongs to: Agent, Conversation |
| **File** | User file storage | Belongs to: User |

### Services

#### AIBackendManager

Factory and registry for AI backends with configuration management.

```php
// Get configured backend for an agent
$result = $manager->forAgent($agent);
$backend = $result['backend'];  // AIBackendInterface implementation
$config = $result['config'];    // NormalizedModelConfig
```

**Responsibilities:**
- Instantiate backend drivers (Ollama, Anthropic, OpenAI)
- Merge configuration from defaults → global config → agent overrides
- Return properly configured backend instances

#### ConversationService

High-level orchestration for conversation lifecycle.

```php
// Process a user message
$state = $conversationService->processMessage($conversation, $message, $images);

// Submit tool result
$state = $conversationService->submitToolResult($conversation, $callId, $result);
```

**Responsibilities:**
- Add messages to conversations
- Dispatch ProcessConversationTurn jobs
- Handle tool result submission
- Broadcast status updates

#### PromptAssembler

Assembles final system prompts from ordered templates.

```php
$systemPrompt = $promptAssembler->assemble($agent, $conversation);
$context = $promptAssembler->getLastContext();  // For debugging
```

**Responsibilities:**
- Load agent's system prompts in order
- Build context from system, agent, and prompt variables
- Render Blade templates with merged context
- Join sections into final prompt

#### SearchService / WebFetchService

Web integration with caching and retry logic.

```php
// Search the web
$results = $searchService->search(new SearchQuery('query', maxResults: 5));

// Fetch web content
$document = $webFetchService->fetch(new FetchRequest('https://example.com'));
```

**Responsibilities:**
- Execute web requests with retry
- Cache results in Redis
- Extract and normalize content

#### ToolSchemaRegistry

Aggregates available tool schemas for AI model awareness.

```php
$tools = $registry->getToolsForConversation($conversation);
// Returns: client tools + system tools + user tools
```

### Jobs

#### ProcessConversationTurn

The core agentic loop job (5-minute timeout, single attempt).

```php
ProcessConversationTurn::dispatch($conversation);
```

**Workflow:**
1. Load agent with relationships
2. Get AI backend and normalized config
3. Check max turns not exceeded
4. Assemble system prompt
5. Store snapshot on first turn
6. Build context and call AI backend
7. Process tool calls if any
8. Execute system tools or pause for client tools
9. Dispatch next turn if needed

#### PullModelJob

Downloads AI models with progress streaming (2-hour timeout).

```php
PullModelJob::dispatch($backend, $modelName);
```

**Features:**
- Streams progress to Redis list
- Broadcasts: started, progress, completed, failed events

#### CleanupTempFilesJob

Scheduled daily at 02:00 to remove temporary files.

### DTOs (Data Transfer Objects)

Immutable value objects for type safety:

| DTO | Purpose |
|-----|---------|
| **ChatMessage** | Single chat message (role, content, tool_calls, images, thinking) |
| **ToolCall** | AI-requested tool invocation (id, name, arguments) |
| **ToolResult** | Tool execution result (success, output, error, metadata) |
| **AIResponse** | Complete AI response (content, model, tokens, finish_reason, tool_calls) |
| **ModelConfig** | Sparse agent config overrides (model, temperature, max_tokens, etc.) |
| **NormalizedModelConfig** | Fully resolved config for a backend |
| **ConversationState** | Status snapshot for polling responses |
| **SearchQuery** / **SearchResult** | Search request/response |
| **FetchRequest** / **FetchedDocument** | Web fetch request/response |

### Contracts (Interfaces)

#### AIBackendInterface

```php
interface AIBackendInterface
{
    public function withConfig(NormalizedModelConfig $config): self;
    public function execute(Agent $agent, array $context): AIResponse;
    public function streamExecute(Agent $agent, array $context, callable $callback): AIResponse;
    public function getCapabilities(): array;
    public function listModels(): array;
    public function pullModel(string $model, callable $onProgress): void;
    // ...
}
```

#### SearchClientInterface

```php
interface SearchClientInterface
{
    public function search(SearchQuery $query): array;
    public function isAvailable(): bool;
    public function getName(): string;
}
```

#### WebFetchClientInterface

```php
interface WebFetchClientInterface
{
    public function fetch(FetchRequest $request): array;
    public function isAvailable(): bool;
    public function getName(): string;
}
```

## Data Flow

### Message Processing

```
User sends message
       │
       ▼
┌──────────────────────┐
│ ConversationService  │ → Add message to conversation
│   .processMessage()  │ → Dispatch ProcessConversationTurn
└──────────────────────┘
       │
       ▼ (queued job)
┌──────────────────────┐
│ProcessConversationTurn│
│    .handle()         │
└──────────────────────┘
       │
       ├─► PromptAssembler.assemble() → Build system prompt
       │
       ├─► AIBackendManager.forAgent() → Get configured backend
       │
       ├─► backend.streamExecute() → Call AI with streaming
       │         │
       │         └─► ConversationEventBroadcaster.textChunk()
       │
       ▼
┌──────────────────────┐
│  Process Response    │
│  - Parse content     │
│  - Extract tool_calls│
└──────────────────────┘
       │
       ├─► No tools → Mark completed
       │
       └─► Has tools
              │
              ├─► Client tool → Pause, broadcast tool_request
              │
              └─► System/User tool → Execute immediately
                         │
                         └─► Dispatch next ProcessConversationTurn
```

### Configuration Resolution

```
Driver Defaults (from ModelConfigNormalizer)
       │
       ▼
Global Backend Config (from config/ai.php)
       │
       ▼
Agent Model Config (from agent.model_config)
       │
       ▼
┌──────────────────────┐
│ ModelConfigNormalizer│ → Apply model limits
│     .normalize()     │ → Check unsupported params
└──────────────────────┘ → Generate warnings
       │
       ▼
NormalizedModelConfig (fully resolved)
       │
       ▼
backend.withConfig() → Create configured instance
```

### Event Broadcasting

```
┌──────────────────────────┐
│ConversationEventBroadcaster│
└──────────────────────────┘
       │
       ▼ RPUSH to Redis
┌──────────────────────┐
│  Redis List          │ → conversation:{id}:events
│  (with 1hr TTL)      │
└──────────────────────┘
       │
       ▼ BLPOP by client
┌──────────────────────┐
│  SSE Stream Endpoint │ → GET /api/v1/conversations/{id}/stream
└──────────────────────┘
```

## File Structure

```
app/
├── Contracts/           # Interfaces (AIBackendInterface, etc.)
├── DTOs/                # Immutable value objects
├── Http/
│   ├── Controllers/
│   │   └── Api/V1/      # API controllers
│   ├── Requests/        # Form request validation
│   └── Resources/       # API response transformers
├── Jobs/                # Queue jobs
├── Models/              # Eloquent models
├── Providers/           # Service providers
└── Services/
    ├── AI/              # AI backend implementations
    ├── Prompts/         # Prompt assembly
    ├── Search/          # Web search
    └── WebFetch/        # Web content fetching

config/
├── ai.php               # AI backend configuration
├── agent.php            # Agent behavior settings
├── search.php           # Search service configuration
├── webfetch.php         # Web fetch configuration
├── horizon.php          # Queue worker configuration
└── ...

resources/js/
├── components/          # Vue components
├── composables/         # Vue composables
├── pages/               # Inertia pages
├── types/               # TypeScript definitions
└── app.ts               # Application entry

routes/
├── api.php              # API routes (/api/v1/...)
├── web.php              # Web routes (Inertia pages)
├── console.php          # Artisan commands
└── channels.php         # Broadcast channels
```

## Next Steps

- [Requirements](requirements.md) - System requirements
- [Local Development](local-development.md) - Development setup
- [Configuration](configuration.md) - Configuration reference
