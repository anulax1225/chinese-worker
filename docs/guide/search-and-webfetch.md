# Search and Web Fetch

Chinese Worker includes integrated web search and content fetching capabilities that agents can use as tools.

## Overview

| Feature | Tool Name | Service | Description |
|---------|-----------|---------|-------------|
| **Web Search** | `web_search` | SearXNG | Search the web via metasearch engine |
| **Web Fetch** | `web_fetch` | HTTP Client | Fetch and extract content from URLs |

Both features include:
- Automatic retry on failure
- Redis-based caching
- Configurable timeouts
- Rate limiting support

## Web Search

### What is SearXNG?

SearXNG is a free, privacy-respecting metasearch engine that aggregates results from multiple sources (Google, Bing, DuckDuckGo, etc.) without tracking users.

### SearXNG Setup

#### Docker (Recommended)

```bash
docker run -d \
    --name searxng \
    --restart unless-stopped \
    -p 8888:8080 \
    -v searxng-data:/etc/searxng \
    -e SEARXNG_BASE_URL=http://localhost:8888 \
    searxng/searxng
```

#### Docker Compose

```yaml
services:
  searxng:
    image: searxng/searxng
    container_name: searxng
    ports:
      - "8888:8080"
    volumes:
      - searxng-data:/etc/searxng
    environment:
      - SEARXNG_BASE_URL=http://localhost:8888
    restart: unless-stopped

volumes:
  searxng-data:
```

#### With Sail

SearXNG is included in the Sail configuration. No additional setup needed.

### Configuration

```env
# .env
SEARCH_DRIVER=searxng
SEARXNG_URL=http://localhost:8888
SEARXNG_TIMEOUT=10
SEARXNG_ENGINES=google,bing,duckduckgo
SEARXNG_SAFE_SEARCH=1

# Caching
SEARCH_CACHE_ENABLED=true
SEARCH_CACHE_TTL=3600
SEARCH_CACHE_STORE=redis
```

For Sail, use `http://searxng:8080` as the URL.

### SearXNG Settings

Create/edit `/etc/searxng/settings.yml` (or the mounted volume):

```yaml
use_default_settings: true

general:
  debug: false
  instance_name: "Chinese Worker Search"

search:
  safe_search: 1
  autocomplete: ""
  default_lang: "auto"
  formats:
    - html
    - json

engines:
  - name: google
    engine: google
    disabled: false
    weight: 1.2

  - name: bing
    engine: bing
    disabled: false
    weight: 1.0

  - name: duckduckgo
    engine: duckduckgo
    disabled: false
    weight: 1.0

  - name: wikipedia
    engine: wikipedia
    disabled: false
    weight: 0.8

server:
  secret_key: "change-this-to-a-random-string"
  bind_address: "0.0.0.0"
  port: 8080
```

### How Agents Use Web Search

Agents can invoke the `web_search` tool:

```json
{
    "name": "web_search",
    "arguments": {
        "query": "latest Laravel 12 features",
        "max_results": 5
    }
}
```

**Tool Schema:**

```json
{
    "name": "web_search",
    "description": "Search the web for information",
    "parameters": {
        "type": "object",
        "properties": {
            "query": {
                "type": "string",
                "description": "The search query"
            },
            "max_results": {
                "type": "integer",
                "description": "Maximum number of results (default 5)",
                "default": 5
            }
        },
        "required": ["query"]
    }
}
```

**Response Format:**

```json
{
    "success": true,
    "output": "Found 5 results:\n\n1. [Title](url)\nSnippet text...\n\n2. [Title](url)\nSnippet text...",
    "metadata": {
        "query": "latest Laravel 12 features",
        "results_count": 5,
        "cached": false
    }
}
```

### Search Caching

Results are cached in Redis to reduce API calls:

```env
SEARCH_CACHE_ENABLED=true
SEARCH_CACHE_TTL=3600        # 1 hour
SEARCH_CACHE_STORE=redis
```

Cache key format: `search:{md5(query)}`

### Verifying Search Works

```bash
# Test SearXNG directly
curl "http://localhost:8888/search?q=test&format=json"

# Via Tinker
php artisan tinker
>>> app(\App\Services\Search\SearchService::class)->isAvailable()
>>> app(\App\Services\Search\SearchService::class)->search(new \App\DTOs\Search\SearchQuery('test'))
```

## Web Fetch

### Overview

The web fetch feature allows agents to retrieve and extract content from URLs. It's useful for:

- Reading documentation
- Fetching API responses
- Analyzing web page content

### Configuration

```env
WEBFETCH_TIMEOUT=15
WEBFETCH_MAX_SIZE=5242880      # 5MB
WEBFETCH_USER_AGENT="ChineseWorker/1.0 (Web Fetch Bot)"
WEBFETCH_CACHE_ENABLED=true
WEBFETCH_CACHE_TTL=1800        # 30 minutes
WEBFETCH_CACHE_STORE=redis
WEBFETCH_MAX_TEXT=50000        # Max extracted text characters
```

### How Agents Use Web Fetch

Agents can invoke the `web_fetch` tool:

```json
{
    "name": "web_fetch",
    "arguments": {
        "url": "https://laravel.com/docs/12.x/installation"
    }
}
```

**Tool Schema:**

```json
{
    "name": "web_fetch",
    "description": "Fetch and extract content from a URL",
    "parameters": {
        "type": "object",
        "properties": {
            "url": {
                "type": "string",
                "description": "The URL to fetch"
            }
        },
        "required": ["url"]
    }
}
```

**Response Format:**

```json
{
    "success": true,
    "output": "Title: Installation - Laravel\n\nContent:\n[Extracted text content...]",
    "metadata": {
        "url": "https://laravel.com/docs/12.x/installation",
        "title": "Installation - Laravel",
        "content_type": "text/html",
        "content_length": 45230,
        "cached": false
    }
}
```

### Content Extraction

The web fetch service:

1. Fetches the URL with configured User-Agent
2. Validates content type (HTML, JSON, plain text)
3. Extracts main content using Readability-style algorithms
4. Removes scripts, styles, navigation
5. Truncates to max length
6. Caches the result

### Allowed Content Types

```php
'allowed_content_types' => [
    'text/html',
    'text/plain',
    'application/json',
    'application/xml',
    'text/xml',
    'application/xhtml+xml',
],
```

Binary content (images, PDFs, etc.) is not supported.

### Fetch Caching

Results are cached to avoid repeated fetches:

```env
WEBFETCH_CACHE_ENABLED=true
WEBFETCH_CACHE_TTL=1800        # 30 minutes
WEBFETCH_CACHE_STORE=redis
```

Cache key format: `webfetch:{md5(url)}`

### Verifying Web Fetch Works

```bash
# Via Tinker
php artisan tinker
>>> app(\App\Services\WebFetch\WebFetchService::class)->isAvailable()
>>> app(\App\Services\WebFetch\WebFetchService::class)->fetch(new \App\DTOs\WebFetch\FetchRequest('https://example.com'))
```

## Error Handling

Both services include retry logic:

```php
'retry' => [
    'times' => 2,
    'sleep_ms' => 500,
],
```

If a request fails (timeout, connection error), it's retried up to `times` with `sleep_ms` delay between attempts.

### Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| Connection refused | Service not running | Start SearXNG/check network |
| Timeout | Slow response | Increase timeout in config |
| 403 Forbidden | Blocked by target | Check User-Agent, use different source |
| Content too large | Response exceeds max size | Increase `WEBFETCH_MAX_SIZE` |

## Without Web Services

If SearXNG is not configured, the `web_search` tool will not be available to agents. The application will still function, but agents won't be able to search the web.

Similarly, if web fetch is disabled or misconfigured, the `web_fetch` tool won't work.

Check tool availability:

```bash
php artisan tinker
>>> app(\App\Services\Search\SearchService::class)->isAvailable()
>>> app(\App\Services\WebFetch\WebFetchService::class)->isAvailable()
```

## Security Considerations

### Search

- SearXNG doesn't track users or store queries
- Consider running SearXNG internally (not exposed to internet)
- Use safe search to filter inappropriate content

### Web Fetch

- The User-Agent identifies the bot
- Some sites block bot access
- Be respectful of rate limits
- Don't fetch private/authenticated URLs
- Content extraction may not work on JavaScript-heavy sites

### Network Isolation

For production, consider:

```yaml
# docker-compose.yml
services:
  searxng:
    networks:
      - internal
    # No external ports

  app:
    networks:
      - internal
      - external

networks:
  internal:
    internal: true
  external:
```

This prevents SearXNG from being accessed directly from the internet.

## Monitoring

### Cache Hit Rates

```bash
# Via Tinker
php artisan tinker
>>> Redis::keys('search:*')
>>> Redis::keys('webfetch:*')
```

### Service Health

Monitor via Telescope or custom health checks:

```php
// In a health check
public function check(): bool
{
    return app(SearchService::class)->isAvailable()
        && app(WebFetchService::class)->isAvailable();
}
```

## Performance Tips

1. **Enable caching** for frequently searched terms
2. **Increase TTL** for stable content
3. **Reduce max results** if you don't need many
4. **Use appropriate timeouts** - too short causes failures, too long blocks workers
5. **Monitor cache size** in Redis

## Next Steps

- [Queues & Jobs](queues-and-jobs.md) - Background processing
- [Configuration](configuration.md) - Full configuration reference
- [Troubleshooting](troubleshooting.md) - Common issues
