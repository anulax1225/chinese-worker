# CW Copilot

Intelligent inline code completion for VS Code, powered by the Chinese Worker backend. Combines Fill-in-the-Middle (FIM) generation with semantic project-wide retrieval to deliver context-aware suggestions directly in the editor.

## Features

### Inline Code Completion

Real-time inline completion suggestions as you type, with two operating modes:

- **FIM Mode** (Fill-in-the-Middle) — Uses suffix-aware generation for models that support context-aware infilling.
- **Ghost Mode** (default) — Uses conversational multi-turn completion for broader model compatibility.

### Project-Wide Semantic Retrieval

- Automatically indexes project symbols (classes, functions, interfaces, enums, imports, etc.) using VS Code's Language Server Protocol.
- Uses vector embeddings to find semantically similar code chunks across the project.
- Injects the most relevant context into completion prompts for better suggestions.

### Incremental Indexing

- Full background indexing on activation (non-blocking).
- Automatic incremental re-indexing on file save.
- Automatic cleanup when files are deleted.
- Content-hash based change detection — skips re-embedding unchanged code.
- Manual reindex via the `CW Copilot: Reindex Project` command.

### Multi-Model FIM Support

Supports 10+ FIM-capable model families out of the box: Qwen, Qwen3, CodeLlama, StarCoder, SantaCoder, DeepSeek, DeepSeek-v2, Codestral, Mistral, DevStral, CodeGemma, and StableCode.

Each model has configurable token markers for prefix/suffix/middle boundaries, optional repo/file separators, and stop sequences.

### Status Bar Integration

A status bar item shows whether CW Copilot is enabled or disabled. Click it to toggle.

## Requirements

- A running [Chinese Worker](https://github.com/your-org/chinese-worker) instance with the embeddings and generation APIs available.
- A valid API token (Sanctum bearer token) configured in `cw.apiToken`.
- VS Code `^1.109.0`.

## Extension Settings

### Connection & Model

| Setting | Description | Default |
|---|---|---|
| `cw.apiUrl` | Base URL of the Chinese Worker instance | `http://localhost` |
| `cw.apiToken` | Sanctum API bearer token | — |
| `cw.agentId` | Agent ID to use for completions | `1` |

### Completion Behavior

| Setting | Description | Default |
|---|---|---|
| `cw.enabled` | Enable/disable inline completions | `true` |
| `cw.debounceMs` | Delay (ms) before triggering a completion | `300` |
| `cw.maxPrefixLines` | Max lines before cursor sent as prompt | `100` |
| `cw.maxSuffixLines` | Max lines after cursor sent as suffix | `30` |
| `cw.maxTokens` | Maximum tokens in the response | `100` |
| `cw.temperature` | Sampling temperature (0–2) | `0.2` |

### FIM Mode

| Setting | Description | Default |
|---|---|---|
| `cw.enableFIM` | Enable fill-in-the-middle mode | `false` |
| `cw.fimTokenFamily` | FIM token family to use | `qwen` |
| `cw.thinkingModel` | Strip `<think>` blocks from thinking models | `false` |

### Retrieval (Context)

| Setting | Description | Default |
|---|---|---|
| `cw.retrieval.enabled` | Enable project context retrieval | `true` |
| `cw.retrieval.topK` | Max code chunks to retrieve | `3` |
| `cw.retrieval.threshold` | Minimum similarity score (0–1) | `0.5` |
| `cw.retrieval.maxLines` | Maximum total lines of context to inject | `50` |
| `cw.retrieval.timeoutMs` | Max wait time (ms) for retrieval before fallback | `5000` |

## Commands

| Command | Description |
|---|---|
| `CW Copilot: Toggle` | Enable or disable inline completions |
| `CW Copilot: Reindex Project` | Manually reindex the entire project |

## Project Configuration

CW Copilot uses a `.cw/` directory at the project root for local configuration and index storage.

### `.cw/config.json`

Controls which files are scanned for indexing:

```json
{
  "scan": ["."],
  "ignore": ["node_modules", ".git", "vendor", "dist", "out", "build", ".cw"]
}
```

### `.cw/fim-tokens.json`

Defines FIM token families. Each entry contains `prefix`, `suffix`, `middle` markers, optional `repoName`/`fileSep` tokens, `stop` sequences, and a `modelPattern` regex for auto-detection.

### `.cw/index.json`

Persistent index of all embedded symbols. Managed automatically — should be added to `.gitignore`.

## Architecture

```
src/
├── extension.ts           # Activation & lifecycle
├── config.ts              # Configuration loader
├── api/
│   └── client.ts          # HTTP client for Chinese Worker APIs
├── copilot/
│   ├── provider.ts        # InlineCompletionItemProvider
│   ├── context.ts         # FIM & Ghost context builders
│   ├── fim-tokens.ts      # FIM token family definitions
│   └── languages.ts       # Language-specific stop sequences & comments
├── indexer/
│   ├── manager.ts         # Full & incremental indexing orchestration
│   ├── scanner.ts         # File system scanning with globs
│   ├── symbols.ts         # Symbol extraction via VS Code LSP
│   ├── enricher.ts        # Symbol enrichment with surrounding code
│   ├── store.ts           # Index persistence (.cw/index.json)
│   ├── hasher.ts          # Content hashing for change detection
│   └── config.ts          # Project config loader
├── retriever/
│   ├── retriever.ts       # Semantic search via embeddings API
│   └── query-builder.ts   # Builds retrieval queries from editor context
└── util/
    ├── logger.ts          # Output channel logging
    └── debounce.ts        # Debounce utilities
```

## Release Notes

### 0.0.1

Initial release.
