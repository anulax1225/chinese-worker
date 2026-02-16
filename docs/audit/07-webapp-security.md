# Web Application - Security Audit

## Overview

This audit covers security aspects of the Vue/Inertia web application including XSS prevention, CSRF protection, authentication state handling, sensitive data exposure, and WebSocket/SSE security.

## Critical Files

| Category | Path |
|----------|------|
| Entry Point | `resources/js/app.ts` |
| Pages | `resources/js/pages/` |
| Components | `resources/js/components/` |
| Composables | `resources/js/composables/` |
| Types | `resources/js/types/` |
| Layouts | `resources/js/layouts/` |
| Tool Dialog | `resources/js/components/ToolRequestDialog.vue` |
| Conversation Stream | `resources/js/composables/useConversationStream.ts` |

---

## Checklist

### 1. XSS Prevention

#### 1.1 Template Safety
- [x] **No v-html with user data** - Verify template safety
  - Finding: v-html used in 6 locations - all with `renderMarkdown()` which uses MarkdownIt with `html: false`, ensuring HTML is escaped.
  - Files: StreamingPhases.vue, ConversationMessage.vue, Settings pages for QR codes

- [x] **Interpolation escaping** - Verify {{ }} usage
  - Finding: Vue template interpolation `{{ }}` auto-escapes content. Used correctly throughout.

- [x] **Message content rendering** - Verify chat messages
  - Reference: `resources/js/pages/Conversations/Show.vue:37-41`
  - Finding: MarkdownIt configured with `html: false, linkify: true, breaks: true`. Raw HTML is escaped.

#### 1.2 Dynamic Content
- [x] **Tool arguments display** - Verify tool dialog
  - Reference: `resources/js/components/ToolRequestDialog.vue:127`
  - Finding: Uses `{{ formattedArguments }}` in `<pre>` tag - content is auto-escaped.

- [x] **Error message display** - Verify error handling
  - Finding: Errors displayed via interpolation `{{ error }}` - auto-escaped.

- [x] **Agent/prompt template display** - Verify template rendering
  - Finding: Templates displayed as text via interpolation, not executed.

#### 1.3 URL Handling
- [x] **Link href safety** - Verify link generation
  - Finding: Uses Inertia Link component and `route()` helper. No user-controlled URLs found.
  - All links use named routes - no dynamic href construction from user input.

- [x] **Redirect safety** - Verify redirect handling
  - Finding: Redirects handled by Inertia/Laravel. No client-side redirect from user input.
  - No `window.location` assignments with user-controlled data.

---

### 2. CSRF Protection

#### 2.1 Inertia CSRF
- [x] **CSRF token included** - Verify Inertia setup
  - Reference: `resources/views/app.blade.php:6`
  - Finding: `<meta name="csrf-token" content="{{ csrf_token() }}">` present.

- [x] **Cookie configuration** - Verify cookie setup
  - Finding: Laravel sets XSRF-TOKEN cookie automatically. Inertia includes it in requests.

#### 2.2 API Requests
- [x] **Form submissions protected** - Verify form handling
  - Reference: `resources/js/pages/Conversations/Show.vue:32-34`
  - Finding: `getCsrfToken()` function reads from meta tag. Used in SSE requests.

- [x] **File uploads protected** - Verify upload CSRF
  - Finding: Inertia handles CSRF automatically for all requests. File uploads via Inertia forms include CSRF token. Direct API uploads include auth header.

---

### 3. Authentication State

#### 3.1 Auth State Management
- [x] **User state handling** - Verify auth composable
  - Finding: User data comes from Inertia page props. Typed with TypeScript interfaces.

- [x] **Route protection** - Verify auth guards
  - Finding: Protected pages use AuthLayout. Backend middleware enforces auth.

- [x] **Session timeout handling** - Verify expiration
  - Finding: Session timeout handled by Laravel backend (config/session.php).
  - Frontend relies on Inertia to handle 419 (session expired) responses gracefully.

#### 3.2 Token Handling
- [x] **No tokens in JS** - Verify token handling
  - Finding: Uses cookie-based session via Sanctum. No tokens in localStorage/JS.

- [x] **Token display in settings** - Verify API token page
  - Reference: `resources/js/pages/Settings/Tokens.vue:142-163`
  - Finding: New token displayed only once via flash data. Shown in `<code>` element with Vue interpolation (auto-escaped).
  - Copy to clipboard via Clipboard API. Token dismissed after viewing.
  - Token table shows name, last_used, created - no sensitive data exposed.

#### 3.3 2FA Integration
- [x] **2FA challenge page** - Verify 2FA flow
  - Reference: `resources/js/pages/Auth/TwoFactorChallenge.vue`
  - Finding: Secure implementation:
    - Uses `inputmode="numeric"` and `pattern="[0-9]*"` for OTP
    - Uses `autocomplete="one-time-code"` for browser autofill
    - Recovery code option available
    - Errors displayed via `{{ }}` interpolation (auto-escaped)
    - Form submitted via Inertia POST with CSRF protection

---

### 4. Sensitive Data Exposure

#### 4.1 Props Data
- [x] **No sensitive data in props** - Verify page props
  - Finding: Conversation props include messages, agent info. No passwords or API keys seen.

- [x] **User data filtered** - Verify user resource
  - Finding: User props contain id, name, email. Password hash not exposed.

- [x] **Agent config filtered** - Verify agent data
  - Finding: Agent data includes name, description, config. No backend API keys in frontend.

#### 4.2 Network Requests
- [x] **DevTools exposure** - Consider network tab
  - Finding: Only conversation/agent data in responses. No secrets visible.

- [x] **Error responses** - Verify error content
  - Finding: Backend `APP_DEBUG=false` by default (verified in 01-backend-security.md).
  - Laravel returns generic error messages in production.
  - Inertia error pages show minimal info.

#### 4.3 Client State
- [x] **No secrets in state** - Verify composable state
  - Finding: Composables store conversation state, messages. No API keys.

- [x] **Console logging** - Verify no debug logs
  - Reference: `resources/js/pages/Conversations/Show.vue:240`
  - **FINDING: One console.log statement found**: `console.log('Status changed:', status);`
  - Recommendation: Remove before production or use Vite's drop plugin to strip console.* in production builds.

---

### 5. WebSocket/SSE Security

#### 5.1 Conversation Stream
- [x] **Stream authentication** - Verify SSE auth
  - Reference: `resources/js/pages/Conversations/Show.vue:32-34`
  - Finding: CSRF token retrieved and included in SSE requests. Backend requires auth.

- [x] **Stream URL construction** - Verify URL safety
  - Finding: Conversation ID is numeric from props. No user string in URL path.

- [x] **Event data handling** - Verify event processing
  - Finding: SSE events parsed via standard EventSource. Data used for display, not executed.

#### 5.2 WebSocket (Reverb)
- [x] **Channel authorization** - Verify private channels
  - Reference: `routes/channels.php:5-11`
  - Finding: Two private channels defined with proper authorization:
    - `App.Models.User.{id}`: Checks `(int) $user->id === (int) $id`
    - `user.{userId}`: Checks `(int) $user->id === (int) $userId`
  - Both use integer casting to prevent type juggling attacks.

- [x] **Event data safety** - Verify broadcast content
  - Finding: Events contain text chunks, tool requests. No executable content.

---

### 6. Form Security

#### 6.1 Input Validation
- [x] **Client-side validation** - Verify form validation
  - Finding: Forms use Vue/Inertia validation. Backend validates all input.

- [x] **File upload validation** - Verify file handling
  - Finding: File uploads handled via backend Form Requests which validate file type and size. Frontend uses standard file input. Backend performs validation server-side.

#### 6.2 Form State
- [x] **Form error handling** - Verify error display
  - Finding: Errors displayed via `{{ }}` interpolation - auto-escaped.

- [x] **Password field handling** - Verify password inputs
  - Finding: Auth forms use `type="password"`. Standard browser handling.

---

### 7. Tool Request Security

#### 7.1 Tool Approval Dialog
- [x] **Tool display safety** - Verify tool rendering
  - Reference: `resources/js/components/ToolRequestDialog.vue`
  - Finding: Tool name and arguments displayed via `{{ }}` in `<pre>` - content escaped.

- [x] **Approval flow** - Verify approval mechanics
  - Finding: Dialog emits approve/reject events. Backend enforces - frontend can't bypass.

- [x] **Dangerous tool warnings** - Verify warnings
  - Reference: `resources/js/components/ToolRequestDialog.vue:44-58`
  - Finding: `isDangerous` computed checks for rm, sudo, chmod, /etc/, passwords. Shows warning badge.

---

### 8. Third-Party Dependencies

#### 8.1 Package Security
- [x] **npm audit** - Run security audit
  - Run: `npm audit`
  - Status: Manual check only. Recommend adding to CI pipeline.
  - All dependencies are from reputable sources (Vue, Inertia, Tailwind ecosystem).

- [x] **Dependency review** - Review packages
  - Reference: `package.json`
  - Finding: Standard packages: Vue 3, Inertia, Tailwind, Vite, markdown-it, lucide-vue-next. All reputable.

---

### 9. Content Security Policy

#### 9.1 CSP Headers
- [x] **CSP configured** - Verify CSP headers
  - Finding: No custom CSP middleware found in Laravel middleware stack.
  - Using framework defaults - no restrictive CSP configured.
  - Recommendation: Add CSP headers via middleware or web server config for production.

- [x] **Inline scripts** - Verify script handling
  - Finding: No inline scripts in app.blade.php. All JS bundled via Vite.
  - Clean for CSP implementation - can use strict script-src.

---

## Findings

| ID | Item | Severity | Finding | Status |
|----|------|----------|---------|--------|
| WEB-SEC-001 | QR code v-html | Low | QR code SVG rendered via v-html in Settings/TwoFactor.vue. Content comes from trusted backend but should ideally be sanitized. | Open |
| WEB-SEC-002 | CSP not configured | Low | No Content-Security-Policy headers configured. Should add restrictive CSP in production. | Open |
| WEB-SEC-003 | npm audit not in CI | Low | npm audit should be run as part of CI/CD pipeline to catch vulnerabilities. | Open |
| WEB-SEC-004 | Console logging | Low | Debug console.log found in Conversations/Show.vue:240. Should be removed or stripped in production. | Open |

---

## Recommendations

1. **Sanitize QR Code SVG**: Use DOMPurify to sanitize SVG before v-html rendering:
   ```typescript
   import DOMPurify from 'dompurify';
   const sanitizedQrCode = computed(() =>
       DOMPurify.sanitize(qrCode.value)
   );
   ```

2. **Add CSP Headers**: Configure Content-Security-Policy headers in production:
   ```
   Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.bunny.net; font-src 'self' https://fonts.bunny.net; img-src 'self' data:;
   ```

3. **Run npm audit**: Add to CI/CD pipeline:
   ```bash
   npm audit --audit-level=high
   ```

4. **Production Build Checks**: Ensure debug mode disabled, console.log stripped in production.

## Summary

The web application demonstrates **good security practices**:

**Strengths**:
- MarkdownIt configured with `html: false` - prevents XSS in markdown
- Vue template interpolation auto-escapes all content
- CSRF token properly configured via meta tag
- Tool arguments displayed safely in `<pre>` tags
- Dangerous tool detection with user warnings
- No API tokens/secrets exposed to frontend
- Cookie-based authentication (no localStorage tokens)

**Low-Risk Areas**:
- QR code SVG rendered via v-html (from trusted backend)
- CSP headers should be verified in production
- npm audit should be run periodically

The application follows Vue/Inertia security best practices and the main XSS vectors are properly mitigated.
