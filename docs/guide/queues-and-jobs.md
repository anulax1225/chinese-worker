# Queues and Jobs

Chinese Worker uses Laravel's queue system for background job processing. This is essential for handling AI conversations, which can take significant time.

## Overview

Key jobs in the system:

| Job | Queue | Timeout | Purpose |
|-----|-------|---------|---------|
| `ProcessConversationTurn` | default | 5 min | Core agentic loop |
| `PullModelJob` | default | 2 hr | Download AI models |
| `CleanupTempFilesJob` | low | 2 min | Scheduled cleanup |

## Queue Connections

### Supported Drivers

| Driver | Use Case | Configuration |
|--------|----------|---------------|
| `sync` | Development/debugging | Jobs run immediately in request |
| `database` | Default, reliable | Jobs stored in `jobs` table |
| `redis` | High performance | Jobs stored in Redis |

### Configuration

```env
QUEUE_CONNECTION=database
```

For Sail with Redis:
```env
QUEUE_CONNECTION=redis
REDIS_HOST=redis
```

## Worker Pools (Horizon)

Chinese Worker uses Laravel Horizon for queue management with two worker pools:

### ai-workers (High Priority)

Handles AI conversation jobs:

```php
'ai-workers' => [
    'connection' => 'redis',
    'queue' => ['high', 'default'],
    'processes' => 10,        // Production: 10, Local: 3
    'timeout' => 330,         // 5.5 minutes
    'memory' => 256,          // MB
    'tries' => 1,             // No retry (to avoid duplicates)
],
```

### low-priority

Handles cleanup and non-urgent jobs:

```php
'low-priority' => [
    'connection' => 'redis',
    'queue' => ['low'],
    'processes' => 2,         // Production: 2, Local: 1
    'timeout' => 120,         // 2 minutes
    'memory' => 128,          // MB
    'tries' => 3,             // Retry on failure
],
```

## Running Workers

### Development (Simple)

```bash
# Single worker
php artisan queue:work --tries=1 --timeout=0

# Or with composer dev (includes worker)
composer dev
```

### Development (Horizon)

```bash
php artisan horizon
```

Access dashboard at http://localhost/horizon

### Production (Supervisor)

Create `/etc/supervisor/conf.d/chinese-worker.conf`:

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
```

Apply configuration:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start chinese-worker-horizon
```

### Production (systemd)

Create `/etc/systemd/system/chinese-worker-horizon.service`:

```ini
[Unit]
Description=Chinese Worker Horizon
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/chinese-worker
ExecStart=/usr/bin/php artisan horizon
Restart=always
RestartSec=5
StandardOutput=append:/var/www/chinese-worker/storage/logs/horizon.log
StandardError=append:/var/www/chinese-worker/storage/logs/horizon.log

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl enable chinese-worker-horizon
sudo systemctl start chinese-worker-horizon
```

## Key Jobs

### ProcessConversationTurn

The core job that handles AI conversation turns.

**What it does:**
1. Loads the agent with all relationships
2. Gets the AI backend with normalized configuration
3. Checks if max turns exceeded
4. Assembles the system prompt from templates
5. Stores snapshots on first turn
6. Calls the AI backend with streaming
7. Processes tool calls (system/user/client)
8. Dispatches next turn if tools were executed

**Timeout:** 5 minutes (330 seconds)

**Queue:** `default`

**Retries:** 1 (no retry to avoid duplicate responses)

**Memory:** 256MB (for large context windows)

### PullModelJob

Downloads AI models from Ollama.

**What it does:**
1. Connects to Ollama backend
2. Initiates model pull
3. Streams progress updates to Redis
4. Broadcasts completion/failure

**Timeout:** 2 hours (for large models)

**Queue:** `default`

**Progress Tracking:**
```bash
# Monitor progress via API
GET /api/v1/ai-backends/ollama/models/pull/{pullId}/stream
```

### CleanupTempFilesJob

Scheduled cleanup of temporary files.

**Schedule:** Daily at 02:00

**Queue:** `low`

**What it cleans:**
- Temporary uploaded files
- Expired cache entries
- Old log files

## Scheduler

The scheduler runs periodic tasks. Add to crontab:

```bash
* * * * * cd /var/www/chinese-worker && php artisan schedule:run >> /dev/null 2>&1
```

### Scheduled Tasks

| Task | Schedule | Description |
|------|----------|-------------|
| `CleanupTempFilesJob` | Daily 02:00 | Clean temporary files |
| Horizon snapshot | Every 5 min | Metrics collection |
| Telescope pruning | Daily | Remove old telescope entries |

View schedule:

```bash
php artisan schedule:list
```

## Monitoring

### Horizon Dashboard

Access at `/horizon` (authentication required):

- **Overview**: Queue throughput, wait times
- **Metrics**: Job completion rates, failures
- **Recent Jobs**: List of processed jobs
- **Failed Jobs**: Failed job inspection and retry
- **Monitoring**: Tags and custom monitors

### Command Line

```bash
# Queue status
php artisan queue:work --once  # Process one job

# Horizon status
php artisan horizon:status

# List pending jobs
php artisan queue:monitor

# Retry failed jobs
php artisan queue:retry all
```

### Metrics

Horizon collects:
- Jobs processed per minute
- Average wait time
- Average run time
- Failed job count
- Memory usage

## Failed Jobs

### Viewing Failed Jobs

```bash
# List failed jobs
php artisan queue:failed

# View specific job
php artisan queue:failed <id>
```

### Retrying Failed Jobs

```bash
# Retry specific job
php artisan queue:retry <uuid>

# Retry all failed jobs
php artisan queue:retry all

# Flush (delete) all failed jobs
php artisan queue:flush
```

### Via Horizon

1. Go to `/horizon`
2. Click "Failed Jobs"
3. View exception and payload
4. Click "Retry" to requeue

## Troubleshooting

### Jobs Not Processing

1. **Check worker is running:**
   ```bash
   php artisan horizon:status
   # or
   ps aux | grep queue:work
   ```

2. **Check queue connection:**
   ```bash
   php artisan tinker
   >>> Queue::size()
   >>> Queue::size('default')
   ```

3. **Check Redis connection:**
   ```bash
   redis-cli ping
   ```

### Jobs Timing Out

1. **Check job timeout vs worker timeout:**
   - Worker timeout must exceed job timeout
   - Default worker: 330s, job: 300s

2. **Increase timeouts:**
   ```php
   // In Horizon config
   'timeout' => 600,  // 10 minutes
   ```

3. **Check AI backend response time:**
   ```env
   OLLAMA_TIMEOUT=300
   ```

### Memory Issues

1. **Increase memory limit:**
   ```php
   // In Horizon config
   'memory' => 512,  // MB
   ```

2. **Check for memory leaks:**
   - Monitor memory in Horizon dashboard
   - Look for growing memory per job

### Jobs Duplicating

This can happen if:
1. Job times out but completes
2. Worker crashes mid-job
3. Queue connection issues

**Solutions:**
- Use unique job IDs
- Implement idempotency in job logic
- Use `tries = 1` for non-idempotent jobs

## Performance Tuning

### Worker Count

```php
// Local development
'processes' => 3,

// Production (8 CPU cores)
'processes' => 10,

// High-load production
'processes' => 20,
```

Rule of thumb: 1-2 workers per CPU core for AI jobs.

### Memory

```php
// Standard AI jobs
'memory' => 256,

// Large context windows (128K+)
'memory' => 512,
```

### Queue Priority

Use multiple queues for priority:

```php
// High priority
dispatch($job)->onQueue('high');

// Default
dispatch($job);

// Low priority
dispatch($job)->onQueue('low');
```

### Batching

For bulk operations, use job batching:

```php
Bus::batch([
    new ProcessJob($item1),
    new ProcessJob($item2),
    new ProcessJob($item3),
])->dispatch();
```

## Queue Events

Listen to queue events for monitoring:

```php
// AppServiceProvider
Queue::failing(function (JobFailed $event) {
    Log::error('Job failed', [
        'job' => get_class($event->job),
        'exception' => $event->exception->getMessage(),
    ]);
});
```

## Best Practices

1. **Use appropriate timeouts**: AI jobs need longer timeouts than typical jobs
2. **Don't retry AI jobs**: They're not idempotent and may produce duplicate responses
3. **Monitor queue depth**: Alert if jobs are backing up
4. **Use Horizon in production**: Better monitoring and control than raw queue:work
5. **Log job progress**: For long-running jobs, log checkpoints
6. **Handle failures gracefully**: Update conversation status on job failure

## Next Steps

- [Production](production.md) - Production deployment
- [Troubleshooting](troubleshooting.md) - Common issues
- [Configuration](configuration.md) - Full configuration reference
