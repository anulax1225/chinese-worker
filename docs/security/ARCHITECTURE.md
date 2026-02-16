# Security Architecture

## Overview

This document describes the security architecture of Chinese Worker, covering authentication, authorization, and tool execution security.

## Authentication

### Web Application
- Uses Laravel Sanctum with cookie-based authentication
- CSRF protection via meta tag tokens
- 2FA available via Laravel Fortify

### CLI Application
- Uses Bearer token authentication
- Tokens stored in `~/.cw/token.json` (Linux) or platform-appropriate config directory
- Token files are created with 0o600 permissions (owner read/write only)
- Config directories are created with 0o700 permissions

### Token Expiration
- API tokens expire after 24 hours (configurable in `config/sanctum.php`)
- CLI users must re-authenticate after token expiry

## Tool Execution Security

### Architecture Note

**IMPORTANT:** The server trusts the CLI client for tool validation.

The security patterns defined in `config/agent.php`:
- `dangerous_patterns` - Patterns for dangerous commands
- `denied_paths` - Paths that should be blocked
- `allowed_patterns` - Explicitly allowed command patterns

These patterns are intended for **client-side validation only**. The server does not enforce these patterns; it trusts that the CLI has validated tool requests before submission.

### Implications

1. **Client-side tools** (Bash, Read, Write, etc.) are validated by the CLI before execution
2. **Server-side tools** (WebFetch, Search) are validated by the server
3. A compromised CLI could bypass client-side validations

### Recommendations for Defense-in-Depth

For enhanced security, consider implementing:
1. Server-side validation of tool arguments for sensitive operations
2. Allow-listing of specific tool operations per user/agent
3. Audit logging of all tool executions
4. Rate limiting on tool execution endpoints

## API Rate Limiting

- API endpoints are rate-limited to 60 requests per minute per user
- Rate limiting headers are returned with all API responses
- 429 responses include `Retry-After` header

## SSRF Protection

WebFetch operations use `UrlSecurityValidator` to:
- Block requests to private IP ranges (10.x.x.x, 172.16-31.x.x, 192.168.x.x)
- Block requests to localhost and loopback addresses
- Block requests to metadata endpoints (169.254.x.x)

## File Upload Security

- File uploads validate MIME types against allowed list
- File size limits enforced (configurable)
- Files stored with randomized names
- Direct file access requires authentication

## Environment Variables

Never commit sensitive values. Required environment variables:
- `APP_KEY` - Application encryption key
- `DB_PASSWORD` - Database credentials
- API keys for AI backends (Anthropic, OpenAI, etc.)

## Audit Trail

Security-relevant events to monitor:
- Authentication attempts (success/failure)
- Token creation/revocation
- Tool execution (especially Bash, Write operations)
- API rate limit violations
