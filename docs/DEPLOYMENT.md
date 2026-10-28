# Deployment guide

This guide targets a Linux server (Ubuntu 22.04/24.04 is a good baseline) running Nginx, PHP-FPM, MySQL 8+ and Supervisor. The application has no public storage symlink requirement because audio is served through authorized controller routes from the private disk.

> Deploying to Vercel instead? Vercel requires external MySQL and S3/R2 storage, direct browser uploads, and the container runtime. See [VERCEL.md](VERCEL.md).

---

## 1. Server packages

```bash
sudo apt update
sudo apt install -y nginx mysql-server ffmpeg supervisor unzip git \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-gd php8.3-intl
```

Adjust the PHP version to the one available on your distribution. Verify FFmpeg includes `libmp3lame`:

```bash
ffmpeg -hide_banner -encoders | grep libmp3lame
```

---

## 2. Application deployment

```bash
sudo mkdir -p /var/www/audio-studio
sudo chown -R "$USER":www-data /var/www/audio-studio
cd /var/www/audio-studio

git clone <your-repository-url> .
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Set the production values in `.env`:

```dotenv
APP_NAME="Audio Effects Studio"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://audio.example.com

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=audio_effects_studio
DB_USERNAME=audio_studio
DB_PASSWORD=use-a-long-random-password

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local

FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe

AUDIO_MAX_UPLOAD_MB=100
AUDIO_MAX_FILES_PER_UPLOAD=10
AUDIO_MAX_FILES_PER_USER=100
AUDIO_STORAGE_QUOTA_MB=2048
AUDIO_MAX_DURATION_SECONDS=1800
AUDIO_MAX_CONCURRENT_JOBS=2
AUDIO_LARGE_UPLOAD_THRESHOLD_MB=25
AUDIO_JOB_TIMEOUT=900
AUDIO_SYNC_EXPORTS=false

AUDIO_TEMP_RETENTION_HOURS=24
AUDIO_OUTPUT_RETENTION_HOURS=24
AUDIO_TRASH_RETENTION_HOURS=24
```

Create the database and user (see the main README for the SQL), then:

```bash
php artisan migrate --force
npm ci
npm run build
php artisan audio:check --fresh
php artisan optimize
```

`php artisan optimize` caches configuration, routes, views and events. Re-run it after changing `.env` or config.

---

## 3. Permissions

The web server and queue worker need write access to private storage and caches:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

Do **not** create a public symlink to `storage/app/private`. The source audio, previews, exports and impulse responses must remain private and are streamed/downloaded through authenticated routes.

If SELinux is enabled, apply the appropriate context to `storage` and `bootstrap/cache`.

---

## 4. PHP-FPM upload limits

Edit the pool configuration, for example `/etc/php/8.3/fpm/pool.d/www.conf`, or a dedicated `php.ini` for FPM:

```ini
upload_max_filesize = 100M
post_max_size = 110M
memory_limit = 512M
max_execution_time = 900
max_input_time = 900
request_terminate_timeout = 900
```

Keep these values consistent with `AUDIO_MAX_UPLOAD_MB`. The repository also ships `public/.user.ini` with matching defaults, but some hosting setups ignore `.user.ini`; the FPM pool/php.ini values are authoritative.

Restart PHP-FPM after changes:

```bash
sudo systemctl restart php8.3-fpm
```

---

## 5. Nginx

```nginx
server {
    listen 80;
    server_name audio.example.com;
    root /var/www/audio-studio/public;
    index index.php;

    client_max_body_size 110m;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 900;
        fastcgi_send_timeout 900;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

For HTTPS, use Certbot or your existing certificate automation:

```bash
sudo certbot --nginx -d audio.example.com
```

Set `SESSION_SECURE_COOKIE=true` and `APP_URL=https://...` when HTTPS is enabled.

---

## 6. Queue worker (Supervisor)

Create `/etc/supervisor/conf.d/audio-studio-worker.conf`:

```ini
[program:audio-studio-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/audio-studio/artisan queue:work --sleep=3 --tries=1 --timeout=900
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/audio-studio/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start audio-studio-worker:*
```

The worker timeout must be greater than `AUDIO_JOB_TIMEOUT`. Two workers are a reasonable default for `AUDIO_MAX_CONCURRENT_JOBS=2`; increase both together if the server has CPU headroom.

After deploying PHP code changes, restart workers so long-running processes load the new classes:

```bash
php artisan queue:restart
```

---

## 7. Scheduler

Add the Laravel scheduler to cron:

```cron
* * * * * cd /var/www/audio-studio && php artisan schedule:run >> /dev/null 2>&1
```

The scheduled cleanup removes expired temporary files, old exports and stale processing records.

---

## 8. Health checks and smoke tests

```bash
php artisan audio:check
php artisan about
php artisan route:list --path=audio
curl -I https://audio.example.com/login
```

Functional checks:

1. Register a new account.
2. Upload a small WAV or MP3 and confirm the waveform and preview appear.
3. Upload a file larger than 25 MB and confirm it moves from `processing` to `ready`.
4. Apply **Slowed + Reverb** and export WAV/MP3.
5. Confirm the export downloads and plays.
6. Delete the file and confirm it disappears from the library and private storage.
7. Check `storage/logs/laravel.log` and the Supervisor worker log for errors.

---

## 9. Backups and retention

Back up both:

- The MySQL database
- `storage/app/private` (source audio, previews, exports, impulse)

Example database backup:

```bash
mysqldump --single-transaction --routines --triggers \
    audio_effects_studio | gzip > /backups/audio-studio-$(date +%F).sql.gz
```

Example storage backup:

```bash
tar -czf /backups/audio-studio-storage-$(date +%F).tar.gz \
    /var/www/audio-studio/storage/app/private
```

The application's retention settings are not a substitute for backups. Adjust `AUDIO_OUTPUT_RETENTION_HOURS` if users need longer access to exported files.

---

## 10. Scaling notes

- FFmpeg is CPU-intensive. Monitor load and queue depth before increasing worker count.
- Keep `AUDIO_MAX_CONCURRENT_JOBS` aligned with available CPU cores.
- Use a shared database queue or Redis for multiple application servers. Local files must be on shared storage if requests can land on different servers.
- If moving to S3/R2, update the private disk, streaming/download responses and FFmpeg temporary paths carefully; FFmpeg needs a local file path or a stream URL.
- Put a CDN or reverse proxy in front of public assets only. Never expose private audio paths.
