# Updating

This guide covers safely updating Chinese Worker to newer versions.

## Before Updating

### 1. Check the Changelog

Review release notes for:
- Breaking changes
- New configuration requirements
- Database migrations
- Dependency updates

### 2. Backup Everything

```bash
# Database backup
mysqldump -u user -p chinese_worker > backup-$(date +%Y%m%d).sql

# Application backup
tar -czf app-backup-$(date +%Y%m%d).tar.gz /var/www/chinese-worker/

# Environment file backup
cp .env .env.backup-$(date +%Y%m%d)
```

### 3. Review Current State

```bash
# Current version (if using git tags)
git describe --tags

# Check for local changes
git status

# Check database migration status
php artisan migrate:status
```

## Update Procedure

### Development (with Sail)

```bash
cd /path/to/chinese-worker

# Stash any local changes
git stash

# Pull latest code
git pull origin main

# Restore local changes
git stash pop

# Update PHP dependencies
./vendor/bin/sail composer install

# Update frontend dependencies
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

# Run migrations
./vendor/bin/sail artisan migrate

# Clear caches
./vendor/bin/sail artisan optimize:clear

# Restart services
./vendor/bin/sail restart
```

### Production

```bash
cd /var/www/chinese-worker

# Enable maintenance mode
php artisan down --secret="update-in-progress"

# Pull latest code
git pull origin main

# Update PHP dependencies
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Update frontend dependencies
npm ci
npm run build

# Run migrations
php artisan migrate --force

# Clear and rebuild caches
php artisan optimize:clear
php artisan optimize
php artisan view:cache

# Terminate Horizon workers (they'll restart automatically)
php artisan horizon:terminate

# Disable maintenance mode
php artisan up

# Verify application is working
curl -s https://your-domain.com/up
```

## Specific Update Scenarios

### Major Version Upgrade

For major version changes (e.g., v1.x to v2.x):

1. **Read upgrade guide carefully**
2. **Test on staging first**
3. **Plan for extended downtime**
4. **Have rollback plan ready**

### Database Schema Changes

If migrations fail:

```bash
# Check migration status
php artisan migrate:status

# Run specific migration
php artisan migrate --path=database/migrations/2024_01_01_000000_migration.php

# If needed, rollback
php artisan migrate:rollback --step=1
```

### Configuration Changes

If new configuration options are added:

```bash
# Check for new config keys
git diff HEAD~1 config/

# Compare with example
diff .env .env.example

# Publish new config files (if any)
php artisan vendor:publish --tag=config
```

### Dependency Conflicts

If Composer fails:

```bash
# Clear composer cache
composer clear-cache

# Update with verbose output
composer update -vvv

# If specific package conflicts
composer why-not package/name version
```

If npm fails:

```bash
# Clear npm cache
npm cache clean --force

# Remove lock file and node_modules
rm -rf node_modules package-lock.json

# Fresh install
npm install
```

## Rollback Procedure

### Quick Rollback (Git)

```bash
# Find previous commit
git log --oneline -10

# Reset to previous commit
git checkout <previous-commit-hash>

# Or reset to previous tag
git checkout v1.2.3

# Restore dependencies
composer install --no-dev
npm ci
npm run build

# Rollback migrations if needed
php artisan migrate:rollback --step=<number-of-new-migrations>

# Clear caches
php artisan optimize:clear
php artisan optimize
```

### Full Rollback (From Backup)

```bash
# Stop services
php artisan down
sudo systemctl stop php8.3-fpm

# Restore application
rm -rf /var/www/chinese-worker
tar -xzf app-backup-20240101.tar.gz -C /

# Restore database
mysql -u user -p chinese_worker < backup-20240101.sql

# Restore environment
cp .env.backup-20240101 .env

# Restart services
sudo systemctl start php8.3-fpm
php artisan optimize
php artisan up
```

## Automated Updates

### Using Git Hooks

Create `.git/hooks/post-merge`:

```bash
#!/bin/bash
# Runs after git pull

changed_files="$(git diff-tree -r --name-only --no-commit-id ORIG_HEAD HEAD)"

# If composer.lock changed
if echo "$changed_files" | grep -q "composer.lock"; then
    composer install --no-dev
fi

# If package-lock.json changed
if echo "$changed_files" | grep -q "package-lock.json"; then
    npm ci
    npm run build
fi

# If migrations added
if echo "$changed_files" | grep -q "database/migrations"; then
    php artisan migrate --force
fi

# Always clear caches
php artisan optimize:clear
php artisan optimize
```

Make executable:

```bash
chmod +x .git/hooks/post-merge
```

### CI/CD Pipeline Example

```yaml
# .github/workflows/deploy.yml
name: Deploy

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Deploy to server
        uses: appleboy/ssh-action@v0.1.7
        with:
          host: ${{ secrets.HOST }}
          username: ${{ secrets.USER }}
          key: ${{ secrets.SSH_KEY }}
          script: |
            cd /var/www/chinese-worker
            php artisan down --secret="${{ secrets.MAINTENANCE_SECRET }}"
            git pull origin main
            composer install --no-dev --optimize-autoloader
            npm ci && npm run build
            php artisan migrate --force
            php artisan optimize
            php artisan horizon:terminate
            php artisan up
```

## Post-Update Verification

### Automated Checks

```bash
# Application responds
curl -f https://your-domain.com/up

# API responds
curl -f https://your-domain.com/api/v1/health

# Queue workers running
php artisan horizon:status

# Check logs for errors
tail -100 storage/logs/laravel.log | grep -i error
```

### Manual Verification

1. **Login/logout works**
2. **Create a test agent**
3. **Start a conversation**
4. **Send a message and receive response**
5. **Check Horizon dashboard**
6. **Verify WebSocket connection (if used)**

## Troubleshooting Updates

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

### Queue Jobs Failing

```bash
# Check failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# If job class changed, flush and restart
php artisan queue:flush
php artisan horizon:terminate
```

### Asset Loading Issues

```bash
# Clear Vite cache
rm -rf public/build

# Rebuild
npm run build

# Clear browser cache
# Add ?v=timestamp to asset URLs temporarily
```

### Database Errors

```bash
# Check connection
php artisan tinker
>>> DB::connection()->getPdo()

# Check migration status
php artisan migrate:status

# If stuck, check for locks
SHOW PROCESSLIST;
```

## Version Tracking

Consider adding version tracking:

```php
// config/app.php
'version' => env('APP_VERSION', '1.0.0'),
```

```env
APP_VERSION=1.2.3
```

Display in UI:

```blade
<span>v{{ config('app.version') }}</span>
```

## Next Steps

- [Security](security.md) - Security best practices
- [Troubleshooting](troubleshooting.md) - Common issues
- [Production](production.md) - Production configuration
