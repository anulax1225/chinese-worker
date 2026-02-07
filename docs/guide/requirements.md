# Requirements

This document lists all software and service requirements for running Chinese Worker.

## Server Requirements

### PHP

**Version:** 8.2 or higher

**Required Extensions:**
- `bcmath` - Arbitrary precision mathematics
- `ctype` - Character type checking
- `curl` - HTTP requests
- `dom` - DOM manipulation
- `fileinfo` - File information
- `json` - JSON encoding/decoding
- `mbstring` - Multibyte string handling
- `openssl` - Encryption
- `pcre` - Regular expressions
- `pdo` - Database abstraction
- `pdo_mysql` - MySQL driver (or `pdo_pgsql` for PostgreSQL)
- `redis` - Redis client (phpredis)
- `tokenizer` - PHP tokenization
- `xml` - XML parsing

Most of these come pre-installed with PHP. On Ubuntu/Debian:

```bash
sudo apt install php8.3 php8.3-bcmath php8.3-curl php8.3-dom \
    php8.3-mbstring php8.3-mysql php8.3-redis php8.3-xml php8.3-zip
```

### Composer

**Version:** 2.x

Install from https://getcomposer.org/download/

### Node.js

**Version:** 20.x or higher (LTS recommended)

Required for frontend asset compilation with Vite.

```bash
# Using nvm (recommended)
nvm install 20
nvm use 20

# Or direct install
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install nodejs
```

### npm or pnpm

Comes with Node.js. pnpm is also supported.

## Database

### MySQL (Recommended)

**Version:** 8.0 or higher

```bash
# Ubuntu/Debian
sudo apt install mysql-server

# Create database
mysql -u root -p
CREATE DATABASE chinese_worker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'app'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON chinese_worker.* TO 'app'@'localhost';
FLUSH PRIVILEGES;
```

### PostgreSQL (Alternative)

**Version:** 14 or higher

```bash
# Ubuntu/Debian
sudo apt install postgresql

# Create database
sudo -u postgres psql
CREATE DATABASE chinese_worker;
CREATE USER app WITH PASSWORD 'your_password';
GRANT ALL PRIVILEGES ON DATABASE chinese_worker TO app;
```

### SQLite (Development Only)

SQLite can be used for local development but is not recommended for production due to queue job handling limitations.

## Cache & Queue

### Redis

**Version:** 6.0 or higher (7.x recommended)

Redis is used for:
- Application caching
- Queue job storage
- SSE event broadcasting
- Search/WebFetch result caching

```bash
# Ubuntu/Debian
sudo apt install redis-server

# Verify
redis-cli ping
# Should return: PONG
```

**Configuration (for production):**
```conf
# /etc/redis/redis.conf
maxmemory 256mb
maxmemory-policy allkeys-lru
```

## AI Backends

You need at least one AI backend configured. Multiple can be enabled simultaneously.

### Ollama (Recommended for Self-Hosting)

Local LLM inference server. Requires no API keys.

**Installation:**
```bash
curl -fsSL https://ollama.com/install.sh | sh

# Pull a model
ollama pull llama3.1

# Verify
ollama list
```

**Hardware Requirements:**
- **Minimum:** 8GB RAM, 4 CPU cores
- **Recommended:** 16GB+ RAM, 8+ cores, GPU (NVIDIA/AMD)
- **Models:** RAM requirements vary (7B: ~4GB, 13B: ~8GB, 70B: ~40GB)

**Supported Models:**
- `llama3.1`, `llama3.2` - Meta's Llama models
- `qwen2.5` - Alibaba's Qwen models
- `mistral` - Mistral AI models
- `deepseek-r1` - DeepSeek reasoning models
- And many more on https://ollama.com/library

### Anthropic Claude (Cloud)

Requires API key from https://console.anthropic.com/

**Supported Models:**
- `claude-sonnet-4-5-20250929` (default)
- `claude-3-5-sonnet-20240620`
- `claude-3-opus-20240229`
- `claude-3-haiku-20240307`

**Cost:** Pay-per-token pricing. See https://anthropic.com/pricing

### OpenAI (Cloud)

Requires API key from https://platform.openai.com/

**Supported Models:**
- `gpt-4` (default)
- `gpt-4-turbo`
- `gpt-4o`
- `gpt-3.5-turbo`

**Cost:** Pay-per-token pricing. See https://openai.com/pricing

## Web Search (Optional)

### SearXNG

Privacy-respecting metasearch engine for web search functionality.

**Docker Installation (Recommended):**
```bash
docker run -d \
  --name searxng \
  -p 8888:8080 \
  -v searxng-data:/etc/searxng \
  searxng/searxng
```

**Configuration (`/etc/searxng/settings.yml`):**
```yaml
search:
  safe_search: 1
  formats:
    - html
    - json
engines:
  - name: google
    disabled: false
  - name: bing
    disabled: false
  - name: duckduckgo
    disabled: false
```

If SearXNG is not configured, web search tools will be unavailable but the application will still function.

## File Storage (Optional)

### Local Filesystem

Default option. Files are stored in `storage/app/`.

### S3-Compatible Storage

For production with multiple servers or cloud deployments:

- **AWS S3**
- **MinIO** (self-hosted S3)
- **RustFS** (included in Sail for development)
- **DigitalOcean Spaces**
- **Cloudflare R2**

## Web Server

### Development

Laravel's built-in server or Laravel Sail (Docker) is sufficient.

### Production

**Nginx (Recommended):**
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/chinese-worker/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

**Apache:**
```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/chinese-worker/public

    <Directory /var/www/chinese-worker/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Process Manager

For production, you need a process manager to run queue workers.

### Supervisor (Recommended)

```bash
sudo apt install supervisor
```

See [Production](production.md) for configuration.

### systemd

Alternative to Supervisor using system services.

## Docker (Optional)

For development with Laravel Sail:

**Docker Engine:** 24.x or higher
**Docker Compose:** 2.x

```bash
# Ubuntu
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
```

## Summary Table

| Component | Minimum | Recommended | Required |
|-----------|---------|-------------|----------|
| PHP | 8.2 | 8.3 | Yes |
| MySQL | 8.0 | 8.0+ | Yes* |
| PostgreSQL | 14 | 15+ | Yes* |
| Redis | 6.0 | 7.x | Yes |
| Node.js | 20.x | 20.x LTS | Yes |
| Ollama | Latest | Latest | Yes** |
| SearXNG | Latest | Latest | No |
| Docker | 24.x | 24.x | No |

\* One database is required (MySQL or PostgreSQL)
\** At least one AI backend is required (Ollama, Anthropic, or OpenAI)

## Hardware Recommendations

### Development Machine
- 8GB RAM minimum (16GB+ with Ollama)
- 4 CPU cores
- 20GB disk space

### Production Server (Small)
- 4GB RAM (without local LLM)
- 2 vCPUs
- 40GB SSD
- External AI API (Anthropic/OpenAI)

### Production Server (With Ollama)
- 16GB+ RAM
- 8+ CPU cores
- NVIDIA GPU (optional but recommended)
- 100GB+ SSD

## Next Steps

- [Local Development](local-development.md) - Set up development environment
- [Installation](installation.md) - Production installation
