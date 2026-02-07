# Introduction

Chinese Worker is a self-hosted AI agent framework that enables you to create, configure, and run intelligent agents powered by large language models. It provides a complete platform for building conversational AI applications with tool execution, web search capabilities, and multi-turn conversations.

## What is Chinese Worker?

At its core, Chinese Worker is:

1. **An Agent Management System** - Create and configure AI agents with custom behaviors, system prompts, and capabilities
2. **A Conversation Platform** - Run multi-turn conversations with context retention and message history
3. **A Tool Execution Framework** - Define and execute tools that agents can use to perform actions
4. **A Multi-Backend AI Gateway** - Switch between different AI providers (Ollama, Anthropic, OpenAI)

## Key Concepts

### Agents

An **Agent** is a configured AI entity with:

- **Name and Description** - Identity information
- **AI Backend** - Which LLM provider to use (Ollama, Claude, OpenAI)
- **Model Configuration** - Temperature, max tokens, context length, and other model parameters
- **System Prompts** - Ordered set of prompt templates that define the agent's behavior
- **Tools** - Capabilities the agent can invoke during conversations
- **Context Variables** - Custom variables available in system prompt templates

```
Agent
├── AI Backend (ollama, claude, openai)
├── Model Config (temperature, max_tokens, etc.)
├── System Prompts (ordered, with variable overrides)
└── Tools (api, function, command types)
```

### Conversations

A **Conversation** is a stateful interaction session between a user and an agent:

- **Messages** - Full history of user, assistant, system, and tool messages
- **Status** - Active, paused (waiting for tool), completed, or failed
- **Turn Tracking** - Number of conversational turns and token usage
- **Tool Requests** - Pending tool executions awaiting results
- **Snapshots** - Frozen system prompt and model config from first turn

Conversations support real-time streaming via Server-Sent Events (SSE), allowing clients to receive response chunks as they're generated.

### System Prompts

**System Prompts** are reusable, template-based instructions that define agent behavior:

- **Blade Templating** - Use Laravel Blade syntax with `{{ $variable }}` placeholders
- **Variable Inheritance** - Context flows from system → agent → prompt defaults → overrides
- **Ordering** - Multiple prompts are assembled in a defined order
- **Activation** - Prompts can be enabled/disabled per agent

Example system prompt template:
```blade
You are {{ $agent_name }}, a helpful AI assistant.

Your primary goal is {{ $primary_goal }}.

Current date: {{ $current_date }}
```

### Tools

**Tools** extend agent capabilities beyond text generation:

**Built-in Tools:**
- `bash` - Execute shell commands
- `read` - Read file contents
- `write` - Write to files
- `edit` - Edit files with find/replace
- `glob` - Find files by pattern
- `grep` - Search file contents

**System Tools:**
- `todo_add`, `todo_list`, `todo_complete`, `todo_update`, `todo_delete`, `todo_clear` - Task management
- `web_search` - Search the web via SearXNG
- `web_fetch` - Fetch and extract web page content

**Custom Tools:**
- `api` - Make HTTP requests to external APIs
- `function` - Execute PHP callables
- `command` - Run shell commands

Tools are defined with JSON schemas that describe their parameters, which are passed to the LLM for function calling.

### AI Backends

Chinese Worker supports multiple AI providers through a unified interface:

| Backend | Type | Features |
|---------|------|----------|
| **Ollama** | Local | Model management, streaming, function calling, vision |
| **Anthropic Claude** | Cloud | Streaming, function calling, extended thinking |
| **OpenAI** | Cloud | Streaming, function calling, vision |

Each backend supports different models with varying capabilities. The system normalizes configuration across backends, applying appropriate defaults and limits.

## How It Works

### Conversation Flow

```
1. User creates conversation with an agent
2. User sends a message
3. System queues ProcessConversationTurn job
4. Job assembles system prompt from templates
5. Job calls AI backend with context
6. AI responds (possibly with tool calls)
7. System executes tools or pauses for client tools
8. Process repeats until conversation completes
```

### Agentic Loop

The core of Chinese Worker is the **agentic loop** - a background job that:

1. Loads the agent with all relationships
2. Resolves and normalizes model configuration
3. Assembles the system prompt from ordered templates
4. Builds the conversation context (messages, tools, turn info)
5. Calls the AI backend with streaming
6. Processes the response (content and/or tool calls)
7. Executes system tools immediately
8. Pauses for client-side tools if needed
9. Dispatches next turn if tools were executed

This loop continues until:
- The AI completes without tool calls
- Maximum turns are reached
- An error occurs
- A tool is refused or fails

### Real-Time Updates

Conversations broadcast events via Redis for real-time client updates:

- `text_chunk` - Streaming text as it's generated
- `tool_request` - Agent requests a tool execution
- `tool_executing` - Server is executing a tool
- `tool_completed` - Tool execution finished
- `completed` - Conversation finished
- `failed` - Error occurred

Clients can consume these via:
- **SSE Stream** - `GET /api/v1/conversations/{id}/stream`
- **Polling** - `GET /api/v1/conversations/{id}/status`

## Use Cases

Chinese Worker is designed for:

- **AI-Powered Assistants** - Build custom assistants with specific capabilities
- **Automation Agents** - Create agents that can execute commands and manage files
- **Research Tools** - Agents that can search the web and summarize information
- **Development Tools** - Code analysis, generation, and execution assistants
- **Custom Integrations** - Connect agents to your own APIs and services

## Next Steps

- [Architecture](architecture.md) - Understand the system components
- [Requirements](requirements.md) - Check what you need to run Chinese Worker
- [Local Development](local-development.md) - Set up a development environment
- [Installation](installation.md) - Deploy to production
