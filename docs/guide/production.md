# Production Deployment

This guide covers optimizing and running Chinese Worker in a production environment.

## Pre-Deployment Checklist

Before deploying to production:

- [ ] All tests pass (`composer test`)
- [ ] Environment variables configured
- [ ] Database migrations ready
- [ ] AI backend accessible
- [ ] SSL certificate obtained
- [ ] Backup strategy in place
- [ ] Monitoring configured

## Environment Configuration

### Essential Production Settings

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Security
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=your-domain.com

# Performance
CACHE_STORE=redis
SESSION_DRIVER=database
QUEUE_CONNECTION=database

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=warning
```

### Sensitive Variables

Never commit these to version control:

```env
APP_KEY=base64:...
DB_PASSWORD=...
REDIS_PASSWORD=...
ANTHROPIC_API_KEY=...
OPENAI_API_KEY=...
REVERB_APP_SECRET=...
```

## Optimization

### Configuration Caching

```bash
# Cache all configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize autoloader
composer dump-autoload --optimize

# All at once
php artisan optimize
```

**Important:** After caching, changes to `.env` or `config/*.php` require re-caching:

```bash
php artisan optimize:clear
php artisan optimize
```

### OPcache

Enable OPcache in `php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.save_comments=1
opcache.fast_shutdown=1
```

**Note:** With `validate_timestamps=0`, you must clear OPcache after deployments:

```bash
# Via CLI
php -r "opcache_reset();"

# Or restart PHP-FPM
sudo systemctl restart php8.3-fpm
```

### Redis Optimization

```conf
# /etc/redis/redis.conf
maxmemory 512mb
maxmemory-policy allkeys-lru
save ""
appendonly no
```

For queue-heavy workloads, consider a dedicated Redis instance for queues.

## Web Server Configuration

### Nginx (Recommended)

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /var/www/chinese-worker/public;

    # SSL
    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256;
    ssl_prefer_server_ciphers off;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';" always;

    # Gzip
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml application/json application/javascript application/xml;

    # Logging
    access_log /var/log/nginx/chinese-worker.access.log combined buffer=512k flush=1m;
    error_log /var/log/nginx/chinese-worker.error.log warn;

    # Limits
    client_max_body_size 10M;
    client_body_timeout 60s;

    # Static files
    location ~* \.(css|js|jpg|jpeg|png|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # PHP handling
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 300;
    }

    # Deny hidden files
    location ~ /\. {
        deny all;
    }

    # Health check
    location /up {
        access_log off;
        try_files $uri /index.php?$query_string;
    }
}
```

### PHP-FPM Pool

```ini
; /etc/php/8.3/fpm/pool.d/chinese-worker.conf
[chinese-worker]
user = www-data
group = www-data
listen = /var/run/php/php8.3-fpm-chinese-worker.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 500

request_terminate_timeout = 300
```

## Queue Workers

### Horizon Configuration

```php
// config/horizon.php
'environments' => [
    'production' => [
        'ai-workers' => [
            'connection' => 'redis',
            'queue' => ['high', 'default'],
            'balance' => 'auto',
            'processes' => 10,
            'timeout' => 330,
            'memory' => 256,
            'tries' => 1,
        ],
        'low-priority' => [
            'connection' => 'redis',
            'queue' => ['low'],
            'balance' => 'auto',
            'processes' => 2,
            'timeout' => 120,
            'memory' => 128,
            'tries' => 3,
        ],
    ],
],
```

### Supervisor Configuration

```ini
; /etc/supervisor/conf.d/chinese-worker.conf
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

## Monitoring

### Horizon Dashboard

Access at `/horizon`. Authentication required.

Configure in `app/Providers/HorizonServiceProvider.php`:

```php
protected function gate(): void
{
    Gate::define('viewHorizon', function ($user) {
        return in_array($user->email, [
            'admin@example.com',
        ]);
    });
}
```

### Telescope (Optional)

Telescope is disabled in production by default. To enable:

```php
// app/Providers/TelescopeServiceProvider.php
public function register(): void
{
    Telescope::night();

    $this->hideSensitiveRequestDetails();

    Telescope::filter(function (IncomingEntry $entry) {
        return $this->app->environment('local')
            || $entry->isReportableException()
            || $entry->isFailedJob()
            || $entry->isScheduledTask()
            || $entry->hasMonitoredTag();
    });
}
```

### Health Checks

Laravel provides a health endpoint at `/up`:

```bash
curl -s https://your-domain.com/up
```

Custom health checks:

```php
// routes/web.php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'database' => DB::connection()->getPdo() ? 'connected' : 'error',
        'redis' => Redis::ping() ? 'connected' : 'error',
        'queue' => Queue::size('default'),
    ]);
});
```

### Log Monitoring

Logs are in `storage/logs/`:

```bash
# Tail application logs
tail -f storage/logs/laravel.log

# Tail Horizon logs
tail -f storage/logs/horizon.log
```

For production, consider:
- **Papertrail** - Cloud log aggregation
- **Sentry** - Error tracking
- **Datadog** - Full observability

## Backups

### Database Backup

```bash
# Manual backup
mysqldump -u user -p chinese_worker > backup.sql

# Automated with cron
0 2 * * * mysqldump -u user -pPASSWORD chinese_worker | gzip > /backups/db-$(date +\%Y\%m\%d).sql.gz
```

### File Backup

```bash
# Storage directory
tar -czf storage-backup.tar.gz storage/app/

# Full application (excluding vendor/node_modules)
tar --exclude='vendor' --exclude='node_modules' -czf app-backup.tar.gz /var/www/chinese-worker/
```

### Backup Strategy

1. **Database:** Daily, retain 7 days
2. **Files:** Daily, retain 7 days
3. **Full backup:** Weekly, retain 4 weeks
4. **Off-site:** Sync to S3/remote storage

## Scaling

### Vertical Scaling

Increase server resources:
- More CPU cores → More queue workers
- More RAM → Larger AI context windows
- Faster disk → Better log/cache performance

### Horizontal Scaling

Run multiple application servers:

1. **Shared database:** Single MySQL instance
2. **Shared Redis:** For cache, sessions, queues
3. **Load balancer:** Nginx, HAProxy, or cloud LB
4. **Sticky sessions:** For WebSocket support

```nginx
# Load balancer example
upstream chinese_worker {
    server 10.0.0.1:80;
    server 10.0.0.2:80;
    server 10.0.0.3:80;
}

server {
    listen 443 ssl;
    location / {
        proxy_pass http://chinese_worker;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

### Queue Scaling

Run Horizon on dedicated servers:

1. Install application on queue server
2. Configure same Redis connection
3. Run only Horizon (no web server)

```bash
# Queue server only runs Horizon
php artisan horizon
```

## Deployment Process

### Zero-Downtime Deployment

```bash
#!/bin/bash
# deploy.sh

set -e

cd /var/www/chinese-worker

# Pull latest code
git pull origin main

# Install dependencies
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Build frontend
npm ci
npm run build

# Clear and rebuild caches
php artisan optimize:clear
php artisan optimize
php artisan view:cache

# Run migrations
php artisan migrate --force

# Restart queue workers
php artisan horizon:terminate

# Restart PHP-FPM (optional, for OPcache)
sudo systemctl reload php8.3-fpm
```

### Maintenance Mode

```bash
# Enable maintenance mode
php artisan down --secret="your-secret-token"

# Access site during maintenance
https://your-domain.com?secret=your-secret-token

# Disable maintenance mode
php artisan up
```

## Performance Monitoring

### Key Metrics

| Metric | Target | Action if exceeded |
|--------|--------|-------------------|
| Response time (p95) | < 500ms | Check DB queries, add caching |
| Queue wait time | < 30s | Add workers |
| Memory usage | < 80% | Scale up or optimize |
| CPU usage | < 70% | Scale up or optimize |
| Error rate | < 0.1% | Investigate errors |

### Slow Query Log

Enable in MySQL:

```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 1;
SET GLOBAL slow_query_log_file = '/var/log/mysql/slow.log';
```

## Next Steps

- [Security](security.md) - Security best practices
- [Updating](updating.md) - Safe update procedures
- [Troubleshooting](troubleshooting.md) - Common issues
