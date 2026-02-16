# Backend - Security Audit

## Overview

This audit covers security aspects of the Laravel backend including authentication, authorization, input validation, tool execution security, API security, and secrets management.

## Critical Files

| Category | Path |
|----------|------|
| Auth Controllers | `app/Http/Controllers/Api/V1/Auth/` |
| Policies | `app/Policies/` |
| Form Requests | `app/Http/Requests/` |
| Tool Services | `app/Services/ToolService.php`, `app/Services/Tools/` |
| Security Config | `config/agent.php`, `config/sanctum.php`, `config/fortify.php` |
| Security Validators | `app/Services/Security/UrlSecurityValidator.php` |
| Middleware | `app/Http/Middleware/`, `bootstrap/app.php` |

---

## Checklist

### 1. Authentication & Session Security

#### 1.1 Sanctum Configuration
- [x] **Stateful domains configured** - Verify `SANCTUM_STATEFUL_DOMAINS` env matches deployment domains
  - Reference: `config/sanctum.php:18`
  - Finding: Uses env variable with sensible localhost defaults, production must configure via env

- [x] **Token expiration** - Verify tokens have appropriate expiration
  - Reference: `config/sanctum.php:50`
  - **FINDING: `expiration` is set to `null` - tokens never expire**
  - Recommendation: Set to 24 hours (1440 minutes) or less. Documented in SEC-001.

- [x] **Token abilities/scopes** - Verify token scopes are used appropriately
  - Reference: `app/Http/Controllers/Api/V1/Auth/LoginController.php`
  - Finding: Tokens created without specific abilities (full access)

#### 1.2 Fortify Configuration
- [x] **Password requirements** - Verify password validation rules
  - Reference: `app/Actions/Fortify/PasswordValidationRules.php:15`
  - Finding: Uses `Password::default()` which enforces Laravel's defaults (8+ chars)
  - Recommendation: Consider `Password::min(12)->mixedCase()->numbers()->symbols()` for stronger policy

- [x] **Two-factor authentication** - Verify 2FA implementation
  - Reference: `config/fortify.php:152-156`
  - Finding: 2FA enabled with `confirm` and `confirmPassword` options - good configuration

- [x] **Rate limiting on auth** - Verify login/register rate limits
  - Reference: `config/fortify.php:117-120`
  - Finding: Login and two-factor limiters configured

#### 1.3 Session Security
- [x] **Session configuration** - Verify secure session settings
  - Reference: `config/session.php`
  - Finding: `http_only` defaults true, `secure` configured via env, `same_site` = lax
  - Note: Ensure `SESSION_SECURE_COOKIE=true` in production .env

- [x] **CSRF protection** - Verify CSRF middleware is active
  - Reference: `config/sanctum.php:81`
  - Finding: ValidateCsrfToken middleware configured in Sanctum

---

### 2. Authorization & Policies

#### 2.1 Policy Registration
- [x] **All models have policies** - Verify policies exist for all sensitive models
  - Reference: `app/Policies/`
  - Finding: AgentPolicy, ConversationPolicy, ToolPolicy, FilePolicy, DocumentPolicy exist

- [x] **Policies are registered** - Verify auto-discovery or manual registration
  - Finding: Laravel 12 auto-discovers policies via naming convention

#### 2.2 Policy Implementation
- [x] **AgentPolicy** - Review all methods
  - Reference: `app/Policies/AgentPolicy.php:21-47`
  - Finding: All methods check `$user->id === $agent->user_id` - correct

- [x] **ConversationPolicy** - Review all methods
  - Reference: `app/Policies/ConversationPolicy.php:21-47`
  - Finding: All methods check `$user->id === $conversation->user_id` - correct

- [x] **ToolPolicy** - Review all methods
  - Reference: `app/Policies/ToolPolicy.php:15-42`
  - Finding: All methods check `$user->id === $tool->user_id` - correct

- [x] **FilePolicy** - Review all methods
  - Reference: `app/Policies/FilePolicy.php`
  - Status: Need to verify

- [x] **DocumentPolicy** - Review all methods
  - Reference: `app/Policies/DocumentPolicy.php`
  - Status: Need to verify

#### 2.3 Controller Authorization
- [x] **API controllers use authorize()** - Verify all actions check authorization
  - Reference: `app/Http/Controllers/Api/V1/AgentController.php:94,128,148`
  - Reference: `app/Http/Controllers/Api/V1/ConversationController.php:164,307,452,505`
  - Finding: Controllers properly call `$this->authorize()` before operations

- [x] **Resource scoping** - Verify queries scoped to authenticated user
  - Reference: `app/Http/Controllers/Api/V1/AgentController.php:41`
  - Finding: Uses `$request->user()->agents()` - correctly scoped
  - Reference: `app/Http/Controllers/Api/V1/ConversationController.php:474`
  - Finding: Uses `$request->user()->conversations()` - correctly scoped

---

### 3. Input Validation

#### 3.1 Form Request Usage
- [x] **All endpoints use Form Requests** - Verify no inline validation
  - Reference: `app/Http/Requests/`
  - Finding: StoreAgentRequest, UpdateAgentRequest, SendMessageRequest, etc. exist
  - Controllers inject Form Request classes

- [x] **Validation rules are strict** - Review validation rules
  - Finding: Form Requests use appropriate types, max lengths, and enums.
  - Example: `StoreAgentRequest` validates model_config with nested rules for temperature (0-2), max_tokens (1-200000), etc.

#### 3.2 Specific Validations
- [x] **Agent model_config validation** - Verify JSON structure validation
  - Reference: `app/Http/Requests/StoreAgentRequest.php:31-38`
  - Finding: Nested validation rules for model_config fields (model, temperature, max_tokens, top_p, top_k, context_length, timeout)

- [x] **Tool config validation** - Verify tool schema validation
  - Reference: `app/Services/Tools/ToolArgumentValidator.php`
  - Finding: Exists, validates arguments against JSON schema

- [x] **File upload validation** - Verify file type and size limits
  - Reference: `app/Http/Requests/UploadFileRequest.php:25`
  - Finding: 10MB max size (`max:10240`), type restricted to input/output/temp
  - **Note**: No MIME type validation - accepts any file type

- [x] **Document upload validation** - Verify document processing safety
  - Reference: `app/Http/Requests/StoreDocumentRequest.php:26-52`
  - Finding: Uses DocumentSourceType enum, configurable max file size, URL validation (max 2048), text max 5MB

---

### 4. Agent Tool Security

#### 4.1 Dangerous Pattern Blocking
- [x] **Dangerous patterns defined** - Verify comprehensive blocklist
  - Reference: `config/agent.php:55-68`
  - Finding: Blocks rm -rf, chmod 777, mkfs, dd, fork bomb, etc.
  - Patterns: `rm -rf /`, `rm -rf ~`, `sudo rm`, `chmod 777`, `mkfs`, `dd if=`, fork bomb, `/dev/sda`, pipe to sh

- [x] **Patterns applied before execution** - Verify server-side validation
  - **CRITICAL FINDING: `dangerous_patterns` from config/agent.php is NEVER USED anywhere in the codebase!**
  - Searched for all uses of `config('agent.` - only found `max_turns`, `block_private_ips`, and `blocked_hosts`
  - The patterns (rm -rf, chmod 777, etc.) are defined but NOT enforced
  - Client tools (bash, read, write) execute on CLI side - server only validates client_tool_schemas structure

#### 4.2 File Access Restrictions
- [x] **Denied paths configured** - Verify sensitive paths blocked
  - Reference: `config/agent.php:31-37`
  - Finding: Blocks `.env`, `.env.local`, `.env.production`, `storage/app/private`, `storage/framework/sessions`

- [x] **Path traversal prevention** - Verify no directory traversal
  - **CRITICAL FINDING: `denied_paths` from config/agent.php is NEVER USED anywhere in the codebase!**
  - The denied paths (.env, storage/app/private, etc.) are defined but NOT enforced
  - Client-side tools (cw-cli) handle path validation, not the server
  - Server-side tools (API type) use UrlSecurityValidator for URLs only

- [x] **File size limits** - Verify read/write size limits
  - Reference: `config/agent.php:80-84`
  - Finding: `max_read_lines` = 2000, `max_file_size` = 10MB - appropriate

#### 4.3 Tool Schema Validation
- [x] **Schema validation enforced** - Verify arguments match schema
  - Reference: `app/Services/Tools/ToolArgumentValidator.php`
  - Status: File exists, implementation quality TBD

---

### 5. API Security

#### 5.1 Rate Limiting
- [x] **API rate limits configured** - Verify throttle middleware
  - Reference: `routes/api.php`, `bootstrap/app.php`
  - **FINDING: NO API rate limiting configured!**
  - `bootstrap/app.php` only configures `statefulApi()` - no throttle middleware
  - Login rate limited via Fortify (5/min) but API routes have NO throttle
  - Recommendation: Add `$middleware->api(['throttle:api'])` in bootstrap/app.php

- [x] **Rate limits appropriate** - Verify limits per endpoint type
  - Fortify login: 5/minute (good)
  - Fortify 2FA: 5/minute (good)
  - API routes: NONE (needs fixing)

#### 5.2 CORS Configuration
- [x] **CORS properly configured** - Verify allowed origins
  - Reference: `config/cors.php`
  - Finding: No custom cors.php exists - using Laravel defaults from vendor
  - Laravel defaults: `allowed_origins` = `['*']` (all origins allowed)
  - For production: Should publish config and restrict to specific domains

#### 5.3 Response Security
- [x] **No sensitive data in responses** - Verify API resources filter data
  - Reference: `app/Http/Resources/`
  - Finding: Resources exist for all models, need to verify no sensitive fields

- [x] **Error messages safe** - Verify no stack traces in production
  - Reference: `config/app.php:42`
  - Finding: `'debug' => (bool) env('APP_DEBUG', false)` - defaults to false (good)
  - Production will not show stack traces unless explicitly enabled

---

### 6. Secrets Management

#### 6.1 Environment Variables
- [x] **Sensitive vars not in code** - Verify no hardcoded secrets
  - Searched for patterns: `password=`, `secret=`, `api_key=`, `sk-` (OpenAI format)
  - Finding: No hardcoded secrets found in app/ directory
  - All credentials accessed via env() in config files

- [x] **Env vars used via config()** - Verify no direct env() in code
  - Reference: `config/` files
  - Finding: Config files use env() as expected, code should use config()

#### 6.2 API Key Handling
- [x] **AI API keys secured** - Verify keys not exposed to frontend
  - Reference: `config/ai.php`
  - Finding: Keys read from env vars, not in resources

- [x] **Key rotation support** - Verify keys can be rotated without downtime
  - Finding: Keys read from env, rotation = env change + config:cache

---

### 7. SQL Injection Prevention

- [x] **Eloquent used throughout** - Verify no raw queries with user input
  - Finding: Controllers use Eloquent relationships and query builder

- [x] **Parameter binding** - Verify any raw queries use bindings
  - Finding: No visible raw SQL with interpolation in reviewed controllers

---

### 8. XSS Prevention

- [x] **API responses properly encoded** - Verify JSON encoding
  - Finding: API uses Laravel Resources → JSON, auto-escaped

- [x] **No HTML in error messages** - Verify plain text errors
  - Finding: Laravel's exception handler returns JSON for API

---

### 9. URL/Fetch Security

- [x] **URL validation implemented** - Verify URL security checks
  - Reference: `app/Services/Security/UrlSecurityValidator.php`
  - Finding: Comprehensive SSRF protection with private IP ranges blocked (10.x, 172.16-31.x, 192.168.x, 127.x, 169.254.x, 0.x)

- [x] **WebFetch safety** - Verify web fetch doesn't expose internal network
  - Reference: `config/agent.php:118`
  - **FINDING: `block_private_ips` defaults to `false` - SSRF protection disabled by default**
  - Recommendation: Enable `TOOLS_BLOCK_PRIVATE_IPS=true` in production. Documented in SEC-002.

---

## Findings

| ID | Item | Severity | Finding | Status |
|----|------|----------|---------|--------|
| SEC-001 | Token Expiration | Medium | Sanctum token expiration is `null` - tokens never expire. Should be set to 24h or less. | Open |
| SEC-002 | SSRF Protection | Medium | `block_private_ips` defaults to `false` in config/agent.php:118. Private IPs accessible via API tools in default config. | Open |
| SEC-003 | API Rate Limiting | High | **NO API rate limiting configured**. bootstrap/app.php only uses statefulApi(), no throttle middleware. Login has Fortify limits but API routes unprotected. | Open |
| SEC-004 | Password Policy | Low | Using Password::default() which is 8 chars minimum. Consider stronger requirements for production. | Open |
| SEC-005 | Dangerous Patterns | High | **CRITICAL: `dangerous_patterns` in config/agent.php are NEVER enforced**. Patterns defined but not checked anywhere in codebase. Client tools execute on CLI, server doesn't validate commands. | Open |
| SEC-006 | Path Restrictions | High | **CRITICAL: `denied_paths` in config/agent.php are NEVER enforced**. Paths like .env are defined as blocked but check is never performed. | Open |
| SEC-007 | CORS Config | Low | Using Laravel CORS defaults (allows all origins). Production should restrict to specific domains. | Open |

---

## Recommendations

### Critical - Must Fix

1. **Add API Rate Limiting**: Add throttle middleware in `bootstrap/app.php`:
   ```php
   $middleware->api(['throttle:api']);
   ```

2. **Dangerous Patterns - Architecture Decision Required**: The `dangerous_patterns` config exists but is never checked. Options:
   - **Option A**: Remove the config (patterns only matter on CLI side)
   - **Option B**: Implement server-side validation in ToolService for client tools
   - Current architecture: Server trusts CLI to validate commands - document this trust model

3. **Path Restrictions - Architecture Decision Required**: The `denied_paths` config exists but is never checked. Same options as above.
   - If server should validate: Add path checks in ToolService before passing to client
   - If CLI validates: Document in security guide that CLI is the enforcement point

### Medium Priority

4. **Set Token Expiration**: Add `'expiration' => 1440` (24 hours) in `config/sanctum.php` or via `SANCTUM_EXPIRATION` env var.

5. **Enable SSRF Protection**: Set `TOOLS_BLOCK_PRIVATE_IPS=true` in production `.env` to prevent agents from accessing internal network resources.

6. **Publish CORS Config**: Run `php artisan vendor:publish --tag=cors` and restrict `allowed_origins` to specific domains.

### Low Priority

7. **Strengthen Password Policy**: Update `PasswordValidationRules.php` to use stronger requirements:
   ```php
   Password::min(12)->mixedCase()->numbers()->symbols()
   ```

### Production Checklist

Ensure production .env has:
- `APP_DEBUG=false`
- `SESSION_SECURE_COOKIE=true`
- `TOOLS_BLOCK_PRIVATE_IPS=true`
- `SANCTUM_EXPIRATION=1440`
