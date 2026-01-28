# Chinese Worker CLI

Command-line interface for the Chinese Worker AI agent execution platform.

## Installation

### Requirements

- Python 3.10 or higher
- Chinese Worker backend running (default: http://localhost)

### Install from source

```bash
cd cli
pip install -e .
```

### Install dependencies only

```bash
cd cli
pip install -r requirements.txt
```

## Usage

### Authentication

Login to your Chinese Worker account:

```bash
cw login
```

You'll be prompted for your email and password. Credentials are stored securely in your system keyring.

Check current user:

```bash
cw whoami
```

Logout:

```bash
cw logout
```

### Working with Agents

List your agents:

```bash
cw agents
```

Start a chat session with an agent:

```bash
cw chat <agent_id>
```

Example:

```bash
cw chat 1
```

During the chat:
- Type your messages and press Enter
- The agent will process your request and may execute tools locally
- Builtin tools (bash, read, write, edit, glob, grep) run on your machine
- Type `exit`, `quit`, or `bye` to end the conversation
- Press Ctrl+C to interrupt

### Configuration

Set a custom API URL:

```bash
export CW_API_URL=https://your-api.example.com
```

Or pass it as an option:

```bash
cw --api-url https://your-api.example.com login
cw chat --api-url https://your-api.example.com 1
```

### Polling Configuration

Adjust the polling interval (default: 2 seconds):

```bash
cw chat --poll-interval 5 1
```

## Architecture

### Builtin Tools

The CLI implements 6 builtin tools that execute locally:

1. **bash** - Execute shell commands
2. **read** - Read files from filesystem
3. **write** - Write files to filesystem
4. **edit** - Perform string replacements in files
5. **glob** - Find files matching patterns
6. **grep** - Search file contents with regex

When the server needs a builtin tool executed, it pauses the conversation and sends a tool request to the CLI. The CLI executes the tool locally and submits the result back to the server, which then continues the agentic loop.

### Communication Flow

1. User sends a message via CLI
2. Server runs the agentic loop with AI
3. If AI requests a builtin tool:
   - Server pauses and returns `waiting_for_tool` status
   - CLI polls, detects tool request
   - CLI executes tool locally
   - CLI submits result to server
   - Server resumes loop with tool result
4. If AI requests a system tool (todos) or user tool:
   - Server executes directly without CLI involvement
5. Loop continues until completion or max turns

### Status Polling

The CLI uses HTTP polling to check conversation status:

- **processing**: Server is running the agentic loop
- **waiting_for_tool**: Server needs CLI to execute a builtin tool
- **completed**: Conversation finished successfully
- **failed**: An error occurred

## Development

### Project Structure

```
cli/
├── chinese_worker/
│   ├── __init__.py
│   ├── cli.py              # Main CLI entry point
│   ├── api/
│   │   ├── __init__.py
│   │   ├── auth.py         # Authentication manager
│   │   └── client.py       # API client
│   └── tools/
│       ├── __init__.py
│       ├── base.py         # Base tool class
│       ├── bash.py         # Bash tool
│       ├── read.py         # Read tool
│       ├── write.py        # Write tool
│       ├── edit.py         # Edit tool
│       ├── glob.py         # Glob tool
│       └── grep.py         # Grep tool
├── pyproject.toml
├── requirements.txt
└── README.md
```

### Running Tests

```bash
pip install -e ".[dev]"
pytest
```

### Code Formatting

```bash
black chinese_worker/
ruff check chinese_worker/
```

## License

This project is part of the Chinese Worker platform.
