# Installation

This guide covers installing Chinese Worker for production without Docker.

For development with Docker, see [Local Development](local-development.md).

## Prerequisites

Ensure you have all [requirements](requirements.md) installed:

- PHP 8.2+ with required extensions
- Composer 2.x
- MySQL 8.0+ or PostgreSQL 14+
- Redis 6.0+
- Node.js 20.x
- Nginx or Apache
- Supervisor (for queue workers)
- Ollama, Anthropic API key, or OpenAI API key

## Step 1: Clone the Repository

```bash
cd /var/www
git clone <repository-url> chinese-worker
cd chinese-worker
```

## Step 2: Install PHP Dependencies

```bash
composer install --optimize-autoloader --no-dev
```

## Step 3: Configure Environment

```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

Edit `.env` with your production settings:

```env
APP_NAME="Chinese Worker"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=chinese_worker
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=your_redis_password
REDIS_PORT=6379

# Cache and Queue
CACHE_STORE=redis
QUEUE_CONNECTION=database
SESSION_DRIVER=database

# AI Backend
AI_BACKEND=ollama
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_MODEL=llama3.1

# Or for cloud backends:
# AI_BACKEND=claude
# ANTHROPIC_API_KEY=sk-ant-...
# AI_BACKEND=openai
# OPENAI_API_KEY=sk-...

# Search (optional)
SEARCH_DRIVER=searxng
SEARXNG_URL=http://127.0.0.1:8888

# Broadcasting
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=your-domain.com
REVERB_PORT=8080
REVERB_SCHEME=https
```

## Step 4: Set Up Database

```bash
# Create database (MySQL example)
mysql -u root -p
CREATE DATABASE chinese_worker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'app'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON chinese_worker.* TO 'app'@'localhost';
FLUSH PRIVILEGES;
exit;

# Run migrations
php artisan migrate --force
```

## Step 5: Build Frontend Assets

```bash
# Install npm dependencies
npm ci

# Build for production
npm run build
```

## Step 6: Set Permissions

```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/chinese-worker

# Set directory permissions
sudo find /var/www/chinese-worker -type d -exec chmod 755 {} \;

# Set file permissions
sudo find /var/www/chinese-worker -type f -exec chmod 644 {} \;

# Make storage and cache writable
sudo chmod -R 775 storage bootstrap/cache

# Create storage link
php artisan storage:link
```

## Step 7: Configure Web Server

### Nginx

Create `/etc/nginx/sites-available/chinese-worker`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name your-domain.com;

    root /var/www/chinese-worker/public;
    index index.php;

    # SSL (use certbot or your certificates)
    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Gzip
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml;

    # Logging
    access_log /var/log/nginx/chinese-worker.access.log;
    error_log /var/log/nginx/chinese-worker.error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }

    # Static file caching
    location ~* \.(css|js|jpg|jpeg|png|gif|ico|svg|woff|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/chinese-worker /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### Apache

Create `/etc/apache2/sites-available/chinese-worker.conf`:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    Redirect permanent / https://your-domain.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName your-domain.com
    DocumentRoot /var/www/chinese-worker/public

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/your-domain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/your-domain.com/privkey.pem

    <Directory /var/www/chinese-worker/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/chinese-worker.error.log
    CustomLog ${APACHE_LOG_DIR}/chinese-worker.access.log combined
</VirtualHost>
```

Enable the site:

```bash
sudo a2ensite chinese-worker
sudo a2enmod rewrite ssl
sudo systemctl reload apache2
```

## Step 8: Configure Queue Workers

Create Supervisor configuration `/etc/supervisor/conf.d/chinese-worker.conf`:

```ini
[program:chinese-worker-horizon]
process_name=%(program_name)s
command=php /var/www/chinese-worker/artisan horizon
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/chinese-worker/storage/logs/horizon.log
stopwaitsecs=3600

[program:chinese-worker-reverb]
process_name=%(program_name)s
command=php /var/www/chinese-worker/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/chinese-worker/storage/logs/reverb.log
```

Start Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

## Step 9: Configure Scheduler

Add to system crontab (`sudo crontab -e`):

```cron
* * * * * cd /var/www/chinese-worker && php artisan schedule:run >> /dev/null 2>&1
```

## Step 10: Set Up AI Backend

### Ollama

Install Ollama:
```bash
curl -fsSL https://ollama.com/install.sh | sh
```

Pull a model:
```bash
ollama pull llama3.1
```

Start as a service:
```bash
sudo systemctl enable ollama
sudo systemctl start ollama
```

### Cloud Backends

For Anthropic or OpenAI, just configure the API keys in `.env`:

```env
AI_BACKEND=claude
ANTHROPIC_API_KEY=sk-ant-...
```

## Step 11: Set Up Search (Optional)

### SearXNG with Docker

```bash
docker run -d \
  --name searxng \
  --restart unless-stopped \
  -p 8888:8080 \
  -v searxng-data:/etc/searxng \
  searxng/searxng
```

### SearXNG Native

See https://docs.searxng.org/admin/installation.html

## Step 12: Optimize for Production

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize autoloader
composer dump-autoload --optimize
```

## Step 13: SSL Certificate

Use Let's Encrypt for free SSL:

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

## Step 14: Verify Installation

1. Visit https://your-domain.com
2. Register a user account
3. Create an agent
4. Start a conversation and send a message
5. Check Horizon at https://your-domain.com/horizon

## Post-Installation Checklist

- [ ] Application loads correctly
- [ ] User registration/login works
- [ ] Database migrations ran successfully
- [ ] Queue workers are processing jobs (check Horizon)
- [ ] AI backend is responding
- [ ] SSL certificate is valid
- [ ] Scheduler is running
- [ ] Logs are being written to `storage/logs/`
- [ ] File uploads work
- [ ] WebSocket connection works (for real-time updates)

## Security Checklist

- [ ] `APP_DEBUG=false` in production
- [ ] Strong database password
- [ ] Redis password configured
- [ ] `.env` file is not accessible via web
- [ ] File permissions are correct
- [ ] Firewall configured (allow 80, 443, SSH only)
- [ ] Regular backups configured

## Next Steps

- [Configuration](configuration.md) - Detailed configuration reference
- [Production](production.md) - Production optimization
- [Security](security.md) - Security best practices
