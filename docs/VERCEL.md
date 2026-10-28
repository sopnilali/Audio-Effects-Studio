# Deploying Audio Effects Studio on Vercel

Vercel can run this Laravel application through its **Container runtime**. The
container includes PHP-FPM, Nginx, Composer dependencies, built Vite assets, and
FFmpeg/FFprobe. That is the only practical way to run this app on Vercel because
the normal PHP/serverless runtimes do not include FFmpeg.

This guide prepares a working Vercel deployment. It is not a one-click setup:
Vercel has no persistent local filesystem and no MySQL database, so you must
provide external services.

## What you need

1. A Vercel project linked to this GitHub repository.
2. An external **MySQL 8+** database (PlanetScale, Aiven, AWS RDS, etc.).
3. A private **S3-compatible bucket** (AWS S3, Cloudflare R2, Supabase Storage,
   etc.).
4. Bucket CORS configured for browser uploads.
5. The environment variables from `.env.vercel.example`.

## Temporary demo mode (SQLite + `/tmp`)

If you only need to preview the application, you can skip the external database
and bucket. The container entrypoint creates a SQLite file under `/tmp`, runs
the migrations, and optionally seeds the demo account. Local audio storage also
uses `/tmp`.

```dotenv
APP_URL=https://your-project.vercel.app
DB_CONNECTION=sqlite
DB_DATABASE=/tmp/laravel/database.sqlite
SESSION_DRIVER=file
CACHE_STORE=file
FILESYSTEM_DISK=local
AUDIO_DISK=local
AUDIO_DIRECT_UPLOAD=false
AUDIO_MAX_UPLOAD_MB=4
AUDIO_LARGE_UPLOAD_THRESHOLD_MB=3
AUDIO_STORAGE_QUOTA_MB=512
AUDIO_MAX_DURATION_SECONDS=600
QUEUE_CONNECTION=sync
AUDIO_SYNC_EXPORTS=true
RUN_MIGRATIONS=true
```

The studio opens directly; there is no login or registration flow.

This mode is intentionally ephemeral:

- The SQLite database and uploaded audio disappear whenever the container is
  replaced, redeployed, or scaled to another instance.
- The upload cap is 4 MB because Vercel limits normal request bodies to 4.5 MB.
- `RUN_MIGRATIONS=true` must stay enabled because each new container starts with
  an empty `/tmp`.
- Do not use this mode for real user data.

For persistent data, continue with the MySQL and S3/R2 setup below.

## Why external storage is required

Vercel Functions and Containers have a read-only filesystem except for `/tmp`.
`/tmp` is ephemeral and is not shared reliably between instances. Uploaded
audio, previews, and exports must therefore live in S3/R2. The app supports this
through `AUDIO_DISK=s3`.

The container sets `LARAVEL_STORAGE_PATH=/tmp/laravel/storage` for framework
cache, sessions, compiled views, and temporary FFmpeg files. Do not point that
at a persistent path on Vercel.

## Why direct upload is required for large files

Vercel limits a Function request body to **4.5 MB**. A normal HTML upload to
`/audio/upload` would fail for almost every real audio file.

Set `AUDIO_DIRECT_UPLOAD=true`. The browser then:

1. asks Laravel for a short-lived presigned S3 PUT URL;
2. uploads the audio directly to the private bucket;
3. calls Laravel to verify and register the object.

The Laravel container never receives the large file body. This is why the S3
bucket must allow `PUT` from your Vercel domain.

## 1. Create the database

Create a MySQL 8+ database and copy the connection values. If your provider
requires TLS, set:

```dotenv
MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt
```

Run migrations during the first deployment with `RUN_MIGRATIONS=true`. After the
schema exists, set it to `false` so every cold start does not attempt a
migration.

## 2. Create the private bucket

Create a private bucket and an access key with read/write permission for that
bucket. Do not make the bucket public.

### AWS S3

```dotenv
FILESYSTEM_DISK=s3
AUDIO_DISK=s3
AUDIO_DIRECT_UPLOAD=true
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=ap-south-1
AWS_BUCKET=your-private-bucket
```

### Cloudflare R2

```dotenv
FILESYSTEM_DISK=s3
AUDIO_DISK=s3
AUDIO_DIRECT_UPLOAD=true
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=auto
AWS_BUCKET=your-private-bucket
AWS_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

### Bucket CORS

The browser uploads with `PUT` and must be allowed by the bucket. Example CORS
policy (replace the origin with your production URL and preview domains):

```json
[
  {
    "AllowedOrigins": [
      "https://your-project.vercel.app"
    ],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3000
  }
]
```

If you use Vercel preview deployments, add their origins or use a suitable
wildcard policy for testing. Keep the bucket private; previews and downloads are
streamed through Laravel after ownership checks.

## 3. Set Vercel environment variables

Use `.env.vercel.example` as the checklist. The important values are:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-project.vercel.app
APP_KEY=base64:...
LOG_CHANNEL=stderr

DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
AUDIO_SYNC_EXPORTS=true

FILESYSTEM_DISK=s3
AUDIO_DISK=s3
AUDIO_DIRECT_UPLOAD=true
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=...
AWS_BUCKET=...

FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe
AUDIO_JOB_TIMEOUT=240
RUN_MIGRATIONS=true
```

Generate `APP_KEY` locally:

```bash
php artisan key:generate --show
```

Do not commit the real key or credentials. For preview deployments, set
`APP_URL` to the preview URL (or use Vercel's per-environment variables);
otherwise generated links and redirects point at the production domain.

## 4. Deploy

Install and authenticate the Vercel CLI, then link the project:

```bash
npm i -g vercel
vercel link
```

Pull the environment variables for local inspection if needed:

```bash
vercel env pull .env.vercel.local
```

Deploy a preview first:

```bash
vercel
```

When the preview works, deploy production:

```bash
vercel --prod
```

Vercel builds the root `Dockerfile` and serves the container on port 80. The
`vercel.json` file selects the Container framework and the `sin1` region. Change
the region to the one closest to your database and bucket if needed.

After the first successful migration, set `RUN_MIGRATIONS=false` and redeploy.

## 5. Smoke test

After deployment:

1. Open the studio directly; no login is required.
2. Upload a small MP3/WAV.
3. Confirm the waveform and preview appear.
4. Apply **Pure Reverb**, **Slowed + Reverb**, and **Nightcore**.
5. Export MP3 and WAV and download both.
6. Check Vercel Runtime Logs for FFmpeg or S3 errors.

If direct upload fails, check the browser Network tab for the S3 `PUT` request
and verify the bucket CORS policy. If the upload reaches Laravel but
finalization fails, check the bucket credentials and `AWS_BUCKET`.

## Background jobs and limits

This Vercel configuration uses:

```dotenv
QUEUE_CONNECTION=sync
AUDIO_SYNC_EXPORTS=true
```

That means preview generation and exports run inside the HTTP request. It is
appropriate for a demo or small library, but it has limits:

- Vercel Hobby functions time out after 300 seconds.
- Long or heavily processed files may exceed the request timeout.
- A browser request stays open while FFmpeg runs.
- Vercel charges Active CPU time for the processing.

For production, run the Laravel queue worker outside Vercel (Railway, Render,
Fly.io, a small VPS) against the same MySQL database and S3 bucket, or use
Vercel Daemons (public beta) with a dedicated worker image. Then set:

```dotenv
QUEUE_CONNECTION=database
AUDIO_SYNC_EXPORTS=false
```

The worker must have FFmpeg/FFprobe and the same storage/database credentials.
This repository's default Vercel setup deliberately does not enable a daemon,
because daemon root/build-context configuration is easy to get wrong and the
worker is not required for a smoke-test deployment.

## Limitations to know

- Vercel is not a traditional always-on Laravel host. Do not rely on local disk.
- The 4.5 MB request/response limit applies to non-streaming function bodies.
  Large uploads use direct S3 upload; downloads are streamed.
- No MySQL or persistent storage is provided by Vercel.
- The container filesystem is read-only except `/tmp`.
- The Laravel scheduler is not running, so retention cleanup is not automatic.
  Run `php artisan audio:cleanup` from an external worker/cron, or add a secured
  cron endpoint, if you need expired exports and trash removed on schedule.
- Cold starts include container startup and Laravel cache warm-up.
- The Vercel Container runtime and Daemons are currently beta features.
- If you need the full 100 MB / 30-minute workflow with a persistent queue and
  simpler operations, a container host such as Railway, Render, Fly.io, or a VPS
  is a better fit than Vercel.

## Local Docker test

You can build the same image locally:

```bash
docker build -t audio-effects-studio .
docker run --rm -p 8080:80 --env-file .env.vercel.local audio-effects-studio
```

Then open `http://localhost:8080`. Use a local MySQL instance or a disposable
external database, and a test S3/R2 bucket. Do not run migrations against
production data from a local container.
