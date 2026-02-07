# Security

This guide covers security best practices for self-hosting Chinese Worker.

## Environment Protection

### .env File

Never expose your `.env` file:

```nginx
# Nginx - deny access to .env
location ~ /\.env {
    deny all;
    return 404;
}
```

```apache
# Apache - deny access to .env
<Files .env>
    Order allow,deny
    Deny from all
</Files>
```

### Sensitive Variables

These should never be committed or exposed:

| Variable | Risk if Exposed |
|----------|-----------------|
| `APP_KEY` | Session/encryption compromise |
| `DB_PASSWORD` | Full database access |
| `REDIS_PASSWORD` | Cache/queue manipulation |
| `ANTHROPIC_API_KEY` | API billing abuse |
| `OPENAI_API_KEY` | API billing abuse |
| `REVERB_APP_SECRET` | WebSocket hijacking |

### Production Settings

```env
APP_ENV=production
APP_DEBUG=false                    # Never true in production
LOG_LEVEL=warning                  # Don't log debug info
SESSION_SECURE_COOKIE=true         # HTTPS only cookies
SESSION_HTTP_ONLY=true             # No JavaScript access
```

## Authentication

### Laravel Sanctum

Chinese Worker uses Sanctum for API authentication. Configuration:

```php
// config/sanctum.php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'your-domain.com')),
'guard' => ['web'],
'expiration' => 60 * 24, // 24 hours
```

### Password Security

```env
BCRYPT_ROUNDS=12    # Increase for stronger hashing (slower)
```

### Two-Factor Authentication

Enabled via Laravel Fortify. Users can enable 2FA in settings.

### Rate Limiting

API endpoints are rate-limited:

```php
// routes/api.php
Route::middleware(['throttle:api'])->group(function () {
    // API routes
});
```

Configure limits in `RouteServiceProvider`:

```php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});
```

## Authorization

### Policies

All models use policies for authorization:

```php
// Example: AgentPolicy
public function view(User $user, Agent $agent): bool
{
    return $user->id === $agent->user_id;
}
```

### Resource Scoping

Controllers scope queries to the authenticated user:

```php
public function index(Request $request)
{
    return AgentResource::collection(
        $request->user()->agents()->paginate()
    );
}
```

## Input Validation

### Form Requests

All inputs are validated via Form Requests:

```php
// StoreAgentRequest.php
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string', 'max:1000'],
        'ai_backend' => ['required', Rule::in(['ollama', 'claude', 'openai'])],
        // ...
    ];
}
```

### SQL Injection Prevention

Always use Eloquent or query builder with bindings:

```php
// Good - parameterized
User::where('email', $email)->first();

// Bad - vulnerable
DB::select("SELECT * FROM users WHERE email = '$email'");
```

### XSS Prevention

Blade automatically escapes output:

```blade
{{-- Safe - escaped --}}
{{ $userInput }}

{{-- Dangerous - unescaped --}}
{!! $userInput !!}
```

## Agent Security

### Command Execution

The agent's bash tool has safety restrictions:

```php
// config/agent.php
'dangerous_patterns' => [
    'rm -rf',
    'chmod 777',
    'mkfs',
    ':(){:|:&};:',  // Fork bomb
    'dd if=',
    '> /dev/',
    // ... more patterns
],

'denied_paths' => [
    '.env',
    '.env.local',
    '.env.production',
    'storage/app/private',
    'storage/framework/sessions',
],
```

### File Access

File operations are restricted:

```php
'file' => [
    'max_read_lines' => 2000,
    'max_file_size' => 10 * 1024 * 1024, // 10MB
],
```

### Tool Permissions

Custom tools should validate inputs:

```php
// In ToolService
public function execute(Tool $tool, array $arguments): ToolResult
{
    // Validate arguments match schema
    $validator = Validator::make($arguments, $tool->config['parameters']);

    if ($validator->fails()) {
        return ToolResult::failure('Invalid arguments');
    }

    // ... execute
}
```

## Network Security

### Firewall Rules

Only expose necessary ports:

```bash
# Allow SSH, HTTP, HTTPS
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

### Internal Services

Keep internal services on private networks:

| Service | Recommended Access |
|---------|-------------------|
| MySQL | localhost or internal network |
| Redis | localhost or internal network |
| Ollama | localhost or internal network |
| SearXNG | localhost or internal network |

### HTTPS/TLS

Always use HTTPS in production:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;

    ssl_certificate /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256;

    # ...
}
```

### Security Headers

```nginx
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;
```

### Content Security Policy

```nginx
add_header Content-Security-Policy "
    default-src 'self';
    script-src 'self' 'unsafe-inline' 'unsafe-eval';
    style-src 'self' 'unsafe-inline';
    img-src 'self' data: https:;
    font-src 'self';
    connect-src 'self' wss://your-domain.com;
" always;
```

## Data Protection

### Encryption at Rest

Enable database encryption if available:

```sql
-- MySQL transparent data encryption
ALTER TABLE conversations ENCRYPTION='Y';
```

### Encryption in Transit

- Use TLS for database connections
- Use TLS for Redis connections
- Use HTTPS for all web traffic
- Use WSS for WebSocket connections

### Data Retention

Implement data cleanup:

```php
// Scheduled job to clean old conversations
$schedule->call(function () {
    Conversation::where('created_at', '<', now()->subDays(30))
        ->where('status', 'completed')
        ->delete();
})->daily();
```

### Backups

Encrypt backups:

```bash
# Encrypted backup
mysqldump database | gpg --symmetric --cipher-algo AES256 > backup.sql.gpg

# Restore
gpg --decrypt backup.sql.gpg | mysql database
```

## Audit Logging

### Activity Logging

Consider logging sensitive actions:

```php
// Log agent creation
Log::info('Agent created', [
    'user_id' => $user->id,
    'agent_id' => $agent->id,
    'ip' => request()->ip(),
]);
```

### Failed Login Tracking

Laravel Fortify handles this. Configure lockout:

```php
// config/fortify.php
'limiters' => [
    'login' => 'login',
],
```

```php
// In AppServiceProvider
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by($request->email.$request->ip());
});
```

## Dependency Security

### Composer Audit

```bash
composer audit
```

### npm Audit

```bash
npm audit
npm audit fix
```

### Automated Scanning

Consider using:
- **Dependabot** (GitHub)
- **Snyk**
- **GitHub Security Advisories**

## Horizon Access

Restrict Horizon dashboard access:

```php
// app/Providers/HorizonServiceProvider.php
protected function gate(): void
{
    Gate::define('viewHorizon', function ($user) {
        return in_array($user->email, [
            'admin@example.com',
        ]);
    });
}
```

## Telescope Access

Restrict Telescope to local environment:

```php
// app/Providers/TelescopeServiceProvider.php
protected function gate(): void
{
    Gate::define('viewTelescope', function ($user) {
        return $this->app->environment('local');
    });
}
```

## API Key Security

### Rotation

Regularly rotate API keys:

1. Generate new key
2. Update `.env`
3. Clear config cache
4. Revoke old key

### Scoping

If possible, use scoped API keys:
- Anthropic: Project-specific keys
- OpenAI: Project-specific keys

## Checklist

### Initial Setup
- [ ] Strong APP_KEY generated
- [ ] Strong database password
- [ ] Redis password configured
- [ ] HTTPS enabled
- [ ] .env not accessible via web
- [ ] APP_DEBUG=false in production

### Authentication
- [ ] Rate limiting enabled
- [ ] Password policy enforced
- [ ] 2FA available
- [ ] Session timeout configured

### Authorization
- [ ] Policies for all models
- [ ] Resource scoping in controllers
- [ ] Horizon access restricted
- [ ] Telescope access restricted

### Network
- [ ] Firewall configured
- [ ] Internal services not exposed
- [ ] TLS for all connections
- [ ] Security headers set

### Monitoring
- [ ] Failed login tracking
- [ ] Error logging configured
- [ ] Audit logging for sensitive actions
- [ ] Backup encryption

### Dependencies
- [ ] Regular security audits
- [ ] Automated vulnerability scanning
- [ ] Update process documented

## Next Steps

- [Production](production.md) - Production deployment
- [Updating](updating.md) - Safe update procedures
- [Troubleshooting](troubleshooting.md) - Common issues
