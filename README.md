# Audio Effects Studio

A complete Laravel 12 SaaS-style audio editor built with Blade, Bootstrap 5, vanilla ES6 JavaScript, WaveSurfer.js and **real FFmpeg processing**. Upload a song, inspect its real waveform, slow it down or speed it up, add reverb and bass, shift pitch independently, and export a genuine WAV or MP3.

The default **Tape mode** makes speed and pitch move together: slowing a track makes the voice deeper/thicker, and speeding it up makes the voice thinner/higher. An optional independent pitch control can be layered on top.

---

## Feature checklist

### Completed

- [x] Laravel 12 application with authentication (register, login, logout)
- [x] Private per-user audio library with ownership policies
- [x] Drag-and-drop and multi-file upload
- [x] Bengali, Arabic, and other Unicode filenames preserved in the UI and downloads
- [x] Real server-side waveform peaks decoded from the uploaded audio
- [x] Browser-safe MP3 preview generated for every upload
- [x] WaveSurfer waveform, seeking, play/pause, previous/next, volume and mute
- [x] Speed control (0.25x–2.00x) with tape-style pitch coupling
- [x] Independent pitch shifting (0.50x–2.00x) layered on top of tape mode
- [x] Reverb with a deterministic synthetic stereo impulse and dry/wet mix
- [x] Bass boost with a real low-shelf filter
- [x] Presets: Original, Pure Reverb, Slowed + Reverb, Nightcore
- [x] Real FFmpeg export to WAV (`pcm_s16le`) and MP3 (`libmp3lame`)
- [x] Queued exports with progress polling, cancellation, reuse and retention cleanup
- [x] Large-file preparation through a queue job
- [x] Protected preview and export download routes
- [x] Rate limits, CSRF protection, FormRequest validation and shell-free `Process` calls
- [x] Large-upload support through a custom `php artisan serve` command and `public/.user.ini`
- [x] Delete/clear flows remove source, preview, jobs and exported files
- [x] Automated feature, unit and real-FFmpeg integration tests
- [x] Deployment, dependency/license, limitations and checklist documentation

### Not included

- [ ] Multi-track timeline / mixing console
- [ ] Recording from a microphone
- [ ] User-uploaded reverb impulse responses
- [ ] Formant-preserving pitch shifting
- [ ] OAuth / social login and email verification
- [ ] Cloud object storage (S3, R2, etc.)
- [ ] Real-time collaborative editing
- [ ] Mobile native applications

---

## Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.2+ (tested on PHP 8.5), Laravel 12.70 |
| Frontend | Blade, Bootstrap 5.3, vanilla ES6 modules, Vite 7 |
| Audio UI | WaveSurfer.js 7, Web Audio API, AudioWorklet |
| Audio engine | FFmpeg / FFprobe via Symfony Process |
| Database | MySQL 8+ (recommended); SQLite works for local development |
| Queue | Database queue + Laravel jobs |
| Storage | Laravel private local disk (`storage/app/private`) |

---

## Requirements

- PHP **8.2+** with `pdo_mysql` or `pdo_sqlite`, `fileinfo`, `mbstring`, `openssl`
- Composer 2
- Node.js **20+** and npm
- FFmpeg and FFprobe **6+** with the `libmp3lame` encoder
- MySQL **8+** for the intended deployment, or SQLite for a quick local demo
- A queue worker process (or `AUDIO_SYNC_EXPORTS=true` for small local demos)

Check the audio environment at any time:

```bash
php artisan audio:check
php artisan audio:check --fresh   # regenerate the reverb impulse too
```

The command verifies FFmpeg, FFprobe, `libmp3lame`, and the required filters (`atempo`, `asetrate`, `aresample`, `lowshelf`, `afir`, `alimiter`).

---

## Quick start (SQLite, no MySQL)

This is the fastest way to run the project on a development machine.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` for SQLite:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/this-project/database/database.sqlite
```

Create the database file, migrate, and seed the demo account:

```bash
touch database/database.sqlite
php artisan migrate:fresh --seed
npm install
npm run build
```

Start the application and a queue worker in two terminals:

```bash
# Terminal 1 — custom serve command raises PHP upload limits automatically
php artisan serve

# Terminal 2 — processes previews, waveform preparation and exports
php artisan queue:work --tries=1
```

Open <http://localhost:8000>. The seeded demo account is:

```text
demo@audio.test
password
```

> `php artisan serve` is overridden by `App\Console\Commands\ServeCommand`. It starts PHP with `upload_max_filesize=100M`, `post_max_size=110M`, `memory_limit=512M`, `max_execution_time=900` and `max_input_time=900`, derived from `AUDIO_MAX_UPLOAD_MB`.

---

## MySQL 8+ setup

```sql
CREATE DATABASE audio_effects_studio
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER 'audio_studio'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON audio_effects_studio.* TO 'audio_studio'@'localhost';
FLUSH PRIVILEGES;
```

Configure `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=audio_effects_studio
DB_USERNAME=audio_studio
DB_PASSWORD=change-me
```

Then run:

```bash
php artisan migrate --seed
```

The schema uses `utf8mb4`, so Bengali and other Unicode names are stored correctly. Uploaded filenames are never used as filesystem paths; files are stored under UUID names and the original/display name is kept in the database.

---

## FFmpeg installation

### macOS (Homebrew)

```bash
brew install ffmpeg
which ffmpeg
which ffprobe
```

Set the absolute paths if Homebrew is not on the web server's `PATH`:

```dotenv
FFMPEG_PATH=/opt/homebrew/bin/ffmpeg
FFPROBE_PATH=/opt/homebrew/bin/ffprobe
```

### Ubuntu / Debian

```bash
sudo apt update
sudo apt install ffmpeg
```

### Windows

Download a build from <https://www.gyan.dev/ffmpeg/builds/> or install with Chocolatey:

```powershell
choco install ffmpeg
```

Use absolute paths in `.env`, for example:

```dotenv
FFMPEG_PATH=C:/ffmpeg/bin/ffmpeg.exe
FFPROBE_PATH=C:/ffmpeg/bin/ffprobe.exe
```

Make sure the selected build includes `libmp3lame`. The `audio:check` command reports this.

---

## Large uploads (important)

A common cause of “large audio files are not accepted” is PHP’s default upload limit (`upload_max_filesize=2M`, `post_max_size=8M`), not the Laravel validation rule.

This project handles that in three layers:

1. **`php artisan serve`** — the custom serve command injects:

   ```text
   upload_max_filesize=100M
   post_max_size=110M
   memory_limit=512M
   max_execution_time=900
   max_input_time=900
   ```

   The values follow `AUDIO_MAX_UPLOAD_MB`.

2. **`public/.user.ini`** — for PHP-FPM/Apache deployments:

   ```ini
   upload_max_filesize = 100M
   post_max_size = 110M
   memory_limit = 512M
   max_execution_time = 900
   max_input_time = 900
   ```

   Some hosting panels ignore `.user.ini`; in that case set the same values in `php.ini` or the PHP-FPM pool and restart PHP-FPM.

3. **Web server body limit** — for Nginx:

   ```nginx
   client_max_body_size 110m;
   ```

   For Apache, ensure `LimitRequestBody` is not lower than the upload limit.

After changing PHP-FPM or `php.ini`, restart PHP-FPM. After changing queue code, restart the queue worker (`php artisan queue:restart` or restart Supervisor).

If you start PHP manually, pass the same flags:

```bash
php -d upload_max_filesize=100M \
    -d post_max_size=110M \
    -d memory_limit=512M \
    -d max_execution_time=900 \
    -d max_input_time=900 \
    -S 127.0.0.1:8000 -t public
```

Upload limits are configured in `.env`:

```dotenv
AUDIO_MAX_UPLOAD_MB=100
AUDIO_MAX_FILES_PER_UPLOAD=10
AUDIO_MAX_FILES_PER_USER=100
AUDIO_STORAGE_QUOTA_MB=2048
AUDIO_MAX_DURATION_SECONDS=1800
AUDIO_LARGE_UPLOAD_THRESHOLD_MB=25
```

Files larger than `AUDIO_LARGE_UPLOAD_THRESHOLD_MB` are prepared by `ProcessAudioJob`; the UI polls until the status changes from `processing` to `ready`.

---

## How the audio pipeline works

### Upload

1. `UploadAudioRequest` validates type, size, count and quota.
2. The original is copied to `storage/app/private/audio/{userId}/{uuid}.{ext}`.
3. FFprobe reads duration, sample rate, channels, codec and bitrate.
4. A 128 kbps MP3 preview is written to `storage/app/private/previews/{userId}/{uuid}.mp3` so playback works consistently in all browsers.
5. `AudioWaveformService` decodes mono PCM through FFmpeg and stores normalized peak values as JSON.
6. Small files are prepared synchronously; files over the threshold are queued.

### Effects

- **Tape mode (default):** speed changes pitch as well as duration. `0.80x` lowers pitch by about 3.9 semitones and lengthens the track; `1.25x` raises pitch by about 3.9 semitones and shortens it.
- **Independent pitch:** when enabled, the pitch multiplier is layered on top of tape pitch. For example, tape `0.80x` plus independent `1.25x` cancels the pitch change while keeping the slower duration.
- **Server export:** `asetrate` + compensating `atempo` chain + `aresample` produce a real time-domain pitch/tempo change. The output duration always matches the requested speed.
- **Browser preview:** `HTMLMediaElement.playbackRate` with `preservesPitch=false` provides tape preview; an AudioWorklet granular shifter provides the optional independent pitch preview. Server exports are authoritative if a browser lacks AudioWorklet.
- **Reverb:** a deterministic synthetic stereo WAV impulse is generated once and applied with FFmpeg `afir`; the browser uses a generated `ConvolverNode` impulse.
- **Bass:** low-shelf filter at 150 Hz, 0–12 dB mapped from 0–100%.
- **Limiter:** `alimiter=limit=0.95` prevents clipping after the effect chain.

### Export

Exports are queued by default:

```dotenv
AUDIO_SYNC_EXPORTS=false
```

The browser polls `audio.processing.status`; completed jobs expose a protected download route. WAV exports use `pcm_s16le`; MP3 exports use `libmp3lame` at `AUDIO_MP3_BITRATE` (default 192 kbps). Output verification checks the file exists, is non-empty, has the expected codec, and has a positive duration.

---

## Security model

- All studio and processing routes require authentication.
- `AudioFilePolicy` and `AudioProcessingJobPolicy` enforce per-user ownership.
- Original files, previews, impulses and exports live on the **private** local disk, not `public/`.
- Downloads are served through authorized controllers with `X-Content-Type-Options: nosniff`.
- Every state-changing request uses CSRF protection.
- Upload, export and polling endpoints are rate limited.
- FFmpeg and FFprobe are invoked through Symfony `Process` with argument arrays; user input is never interpolated into a shell command.
- Numeric effect values are clamped server-side before they reach a filter graph.
- Uploaded filenames are sanitized for display and download; UUIDs are used for storage paths.
- The app uses the database session driver and database queue by default.

---

## Testing

```bash
php artisan test
```

Or:

```bash
composer test
```

The suite contains:

- Authentication and authorization tests
- Upload validation, Bengali filenames, size/duration/quota limits
- Real waveform and preview generation
- Effect validation, clamping, preset detection and tape-mode filter graphs
- Export queue creation, reuse, cancellation, protected downloads and cleanup
- Cross-user access checks
- Real FFmpeg integration tests for slowed/reverb MP3 and nightcore WAV exports

Real-FFmpeg tests are automatically **skipped** when `ffmpeg`/`ffprobe` are unavailable. Run the environment check first:

```bash
php artisan audio:check
```

Static formatting:

```bash
vendor/bin/pint
```

The current local suite passes: **68 tests, 314 assertions** (numbers may grow as tests are added).

---

## Queue and scheduler

Process previews and exports:

```bash
php artisan queue:work --tries=1 --timeout=900
```

For production, use Supervisor or systemd so the worker restarts automatically. Example Supervisor program:

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

Schedule the retention cleanup job:

```cron
* * * * * cd /var/www/audio-studio && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs `CleanupTemporaryAudioJob` daily at 03:15. Retention is configured with:

```dotenv
AUDIO_TEMP_RETENTION_HOURS=24
AUDIO_OUTPUT_RETENTION_HOURS=24
AUDIO_TRASH_RETENTION_HOURS=24
```

---

## Development commands

```bash
# Build frontend assets once
npm run build

# Vite dev server with HMR
npm run dev

# Start server, queue listener, logs and Vite together
composer run dev

# Rebuild database and demo user
php artisan migrate:fresh --seed

# Clear caches
php artisan optimize:clear

# Check audio engine
php artisan audio:check --fresh
```

`composer run dev` uses `queue:listen`, which reloads code between jobs. A plain `queue:work` process keeps old code in memory, so restart it after changing PHP classes.

---

## Project structure

```text
app/
  Console/Commands/          audio:check and the upload-limit-aware serve command
  Http/Controllers/          studio, upload, streaming, effects, export, processing
  Http/Requests/             upload, rename, effects and export validation
  Jobs/                      preview preparation, export, cleanup
  Models/                    AudioFile, AudioProcessingJob, User
  Policies/                  ownership checks
  Services/Audio/            FFmpeg runner, metadata, waveform, effects, reverb, exports
config/audio.php             limits, presets, filters, retention, bitrates
database/migrations/         users, audio files, processing jobs, queue/session tables
public/.user.ini             PHP-FPM/Apache upload limits
resources/js/                studio controller, waveform, player, upload, export, Web Audio
resources/views/audio/       Blade studio UI and partials
storage/app/private/         source audio, previews, exports, temporary files, impulse
tests/                       feature, unit and real-FFmpeg integration tests
docs/                        deployment, dependencies, limitations, checklist
```

---

## Documentation

- [Deployment guide](docs/DEPLOYMENT.md)
- [Dependencies and licenses](docs/DEPENDENCIES.md)
- [Known limitations](docs/KNOWN_LIMITATIONS.md)
- [Completed/uncompleted checklist](docs/CHECKLIST.md)

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| Large upload returns `422` immediately | PHP `upload_max_filesize`/`post_max_size` are too low. Use `php artisan serve`, or set the values in `php.ini`/PHP-FPM and restart. |
| Upload returns `413 Request Entity Too Large` | Raise Nginx `client_max_body_size` / Apache `LimitRequestBody`. |
| Upload succeeds but stays `processing` | Start a queue worker: `php artisan queue:work --tries=1`. |
| Export stays `pending` | Start the queue worker, or set `AUDIO_SYNC_EXPORTS=true` for a local demo. |
| Export says FFmpeg unavailable | Run `php artisan audio:check`; set `FFMPEG_PATH`/`FFPROBE_PATH` to absolute paths. |
| MP3 export fails | Install an FFmpeg build with `libmp3lame`. |
| Delete button does nothing | Rebuild assets (`npm run build`), hard-refresh the page, and ensure Bootstrap JS loaded. The delete flow uses a Bootstrap confirmation modal. |
| Effects save but old code still runs in background jobs | Restart the queue worker: `php artisan queue:restart`. |
| Pitch preview sounds different from export | Some browsers do not support AudioWorklet or `preservesPitch`. Exports are processed server-side and remain correct. |
| Bengali download name is garbled | Use the `filename*=utf-8''` header; modern browsers decode it. The app also provides an ASCII fallback. |
| `419 Page Expired` | Refresh the page so the CSRF token is current. |
| `SQLSTATE` database errors | Check `.env`, run `php artisan migrate`, and verify the database exists. |

---

## License

The application code is provided under the [MIT license](LICENSE). Third-party dependencies keep their own licenses; see [docs/DEPENDENCIES.md](docs/DEPENDENCIES.md). FFmpeg licensing depends on the exact build and enabled codecs; review your distribution's FFmpeg license before commercial deployment.
