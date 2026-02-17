# Troubleshooting

This guide covers common issues and their solutions when running Chinese Worker.

## Application Issues

### Application Won't Start

**Symptoms:** Blank page, 500 error, or Laravel error page.

**Check:**

```bash
# Check Laravel logs
tail -50 storage/logs/laravel.log

# Check PHP error log
tail -50 /var/log/php/error.log

# Check Nginx error log
tail -50 /var/log/nginx/error.log
```

**Common Causes:**

1. **Missing .env file:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

2. **Missing dependencies:**
   ```bash
   composer install
   npm install && npm run build
   ```

3. **Permission issues:**
   ```bash
   sudo chown -R www-data:www-data storage bootstrap/cache
   chmod -R 775 storage bootstrap/cache
   ```

4. **Cache corruption:**
   ```bash
   php artisan optimize:clear
   ```

### "Class not found" Errors

```bash
composer dump-autoload
php artisan optimize:clear
```

### "View not found" Errors

```bash
php artisan view:clear
php artisan view:cache
```

### "CSRF Token Mismatch"

1. Clear browser cookies
2. Check `SESSION_DOMAIN` in `.env`
3. Ensure `APP_URL` matches actual URL

## Database Issues

### Cannot Connect to Database

```bash
# Test connection
php artisan tinker
>>> DB::connection()->getPdo()
```

**Check:**
- Database server is running
- Credentials in `.env` are correct
- Database exists
- User has permissions

### Migration Failures

```bash
# Check migration status
php artisan migrate:status

# Run specific migration
php artisan migrate --path=database/migrations/specific_migration.php

# Rollback last migration
php artisan migrate:rollback --step=1
```

### "Table doesn't exist" Errors

```bash
# Run all migrations
php artisan migrate --force

# Or fresh start (WARNING: drops all data)
php artisan migrate:fresh --seed
```

## Redis Issues

### Cannot Connect to Redis

```bash
# Test connection
redis-cli ping
# Should return: PONG

# Check from PHP
php artisan tinker
>>> Redis::ping()
```

**Check:**
- Redis is running: `systemctl status redis`
- Host/port in `.env` is correct
- Password is correct (if set)
- Firewall allows connection

### Queue Jobs Not Processing

1. **Check worker is running:**
   ```bash
   php artisan horizon:status
   # or
   ps aux | grep queue:work
   ```

2. **Check queue size:**
   ```bash
   php artisan tinker
   >>> Queue::size('default')
   ```

3. **Try processing one job manually:**
   ```bash
   php artisan queue:work --once
   ```

4. **Check failed jobs:**
   ```bash
   php artisan queue:failed
   ```

## AI Backend Issues

### Ollama Not Responding

```bash
# Check Ollama is running
curl http://localhost:11434/api/tags

# Check with Sail
./vendor/bin/sail exec ollama ollama list

# Restart Ollama
sudo systemctl restart ollama
# or with Sail
./vendor/bin/sail restart ollama

# Check Ollama logs
journalctl -u ollama -f
```

### "Model not found" Errors

```bash
# List available models
ollama list

# Pull the model
ollama pull llama3.1
```

### Slow AI Responses

1. **Check model size:** Larger models need more resources
2. **Check GPU usage:** `nvidia-smi`
3. **Reduce context length:** Lower `contextLength` in agent config
4. **Use smaller model:** Try `llama3.2:3b` instead of larger variants

### API Key Errors (Claude/OpenAI)

```bash
# Verify key is set
php artisan tinker
>>> config('ai.backends.claude.api_key')

# Test API directly
curl https://api.anthropic.com/v1/messages \
    -H "x-api-key: $ANTHROPIC_API_KEY" \
    -H "anthropic-version: 2023-06-01" \
    -H "content-type: application/json" \
    -d '{"model":"claude-3-haiku-20240307","max_tokens":10,"messages":[{"role":"user","content":"Hi"}]}'
```

## Conversation Issues

### Conversation Stuck in "Processing"

1. **Check queue workers are running**
2. **Check for failed jobs:**
   ```bash
   php artisan queue:failed
   ```
3. **Check conversation status:**
   ```bash
   php artisan tinker
   >>> Conversation::find(ID)->status
   ```
4. **Manually reset if needed:**
   ```bash
   >>> $c = Conversation::find(ID);
   >>> $c->update(['status' => 'failed']);
   ```

### Tool Execution Failures

1. **Check tool definition** is valid JSON schema
2. **Check agent has tool attached**
3. **For bash tools,** check command isn't blocked by safety filters
4. **Check tool service logs:**
   ```bash
   grep "tool" storage/logs/laravel.log | tail -20
   ```

### Messages Not Appearing

1. **Clear browser cache**
2. **Check SSE connection** in browser DevTools → Network
3. **Check Redis is working** for event broadcasting
4. **Check Reverb is running:**
   ```bash
   php artisan reverb:start
   ```

## Frontend Issues

### Assets Not Loading

```bash
# Rebuild assets
npm run build

# Check Vite is running (development)
npm run dev
```

### "Vite manifest not found"

```bash
# Build for production
npm run build

# Or ensure Vite dev server is running
npm run dev
```

### Blank Page After Deploy

1. Clear browser cache (Ctrl+Shift+R)
2. Clear Laravel caches:
   ```bash
   php artisan optimize:clear
   php artisan view:cache
   ```
3. Rebuild assets:
   ```bash
   npm run build
   ```

### WebSocket Connection Failed

1. **Check Reverb is running:**
   ```bash
   php artisan reverb:start
   ```

2. **Check Reverb config in `.env`:**
   ```env
   REVERB_HOST=your-domain.com
   REVERB_PORT=8080
   REVERB_SCHEME=https
   ```

3. **Check frontend config matches:**
   ```env
   VITE_REVERB_HOST="${REVERB_HOST}"
   VITE_REVERB_PORT="${REVERB_PORT}"
   VITE_REVERB_SCHEME="${REVERB_SCHEME}"
   ```

4. **Check firewall allows WebSocket port**

## Search/WebFetch Issues

### Search Not Working

```bash
# Test SearXNG directly
curl "http://localhost:8888/search?q=test&format=json"

# Check from PHP
php artisan tinker
>>> app(\App\Services\Search\SearchService::class)->isAvailable()
```

**Check:**
- SearXNG is running
- URL in `.env` is correct
- Network allows connection

### WebFetch Timeouts

1. **Increase timeout:**
   ```env
   WEBFETCH_TIMEOUT=30
   ```

2. **Check target URL is accessible:**
   ```bash
   curl -I https://example.com
   ```

3. **Check for rate limiting** on target site

## Horizon Issues

### Horizon Dashboard Not Loading

1. **Check authentication** - must be logged in
2. **Check authorization** in `HorizonServiceProvider`:
   ```php
   Gate::define('viewHorizon', function ($user) {
       return in_array($user->email, ['admin@example.com']);
   });
   ```

### Workers Keep Restarting

1. **Check memory limit:**
   ```php
   // config/horizon.php
   'memory' => 256, // Increase if needed
   ```

2. **Check for memory leaks in jobs**

3. **Check timeout settings**

### Jobs Timing Out

1. **Increase worker timeout** (must be > job timeout):
   ```php
   'timeout' => 600, // 10 minutes
   ```

2. **Check job timeout:**
   ```php
   // In job class
   public $timeout = 300;
   ```

## Performance Issues

### Slow Page Loads

1. **Enable caching:**
   ```bash
   php artisan optimize
   ```

2. **Check for N+1 queries** in Telescope

3. **Enable OPcache**

4. **Check database indexes**

### High Memory Usage

1. **Check Horizon worker memory:**
   ```bash
   php artisan horizon:status
   ```

2. **Reduce worker count** if server is constrained

3. **Check for memory leaks** in custom jobs

### High CPU Usage

1. **Check queue backlog:**
   ```bash
   php artisan tinker
   >>> Queue::size()
   ```

2. **Check for runaway jobs** in Horizon

3. **Check Ollama** isn't overloaded

## Sail/Docker Issues

### Sail Won't Start

```bash
# Check Docker is running
docker info

# Check for port conflicts
docker ps

# Rebuild containers
./vendor/bin/sail build --no-cache
./vendor/bin/sail up -d
```

### Container Errors

```bash
# Check logs
./vendor/bin/sail logs

# Check specific container
./vendor/bin/sail logs pgsql
./vendor/bin/sail logs ollama
```

### Permission Denied

```bash
# Fix ownership
./vendor/bin/sail exec laravel.test chown -R sail:sail /var/www/html/storage
```

## Getting Help

If you can't resolve an issue:

1. **Check logs thoroughly:**
   ```bash
   tail -100 storage/logs/laravel.log
   ```

2. **Enable debug mode temporarily:**
   ```env
   APP_DEBUG=true
   LOG_LEVEL=debug
   ```

3. **Check Telescope** for detailed request/query info

4. **Search existing issues** on GitHub

5. **Create a new issue** with:
   - Error message and stack trace
   - Steps to reproduce
   - Environment details (PHP version, OS, etc.)
   - Relevant log snippets
