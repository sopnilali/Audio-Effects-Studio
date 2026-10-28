# Known limitations

These are intentional scope boundaries, not hidden bugs. They are listed so operators and users know what to expect.

## Audio processing

- **Tape mode is a resampling pitch shift.** Slowing or speeding changes pitch and duration together, like a turntable. It does not preserve formants, so extreme settings can sound unnatural.
- **Independent pitch uses a time-domain granular shifter.** It is real pitch shifting, but it is not a studio-grade formant-preserving phase vocoder. Extreme values can produce artifacts.
- **Browser preview is an approximation.** Server exports are authoritative. Tape preview depends on `HTMLMediaElement.preservesPitch`; independent pitch preview depends on AudioWorklet support.
- **One reverb character.** The impulse is a deterministic synthetic hall. Users cannot upload their own impulse responses in this version.
- **Fixed bass shelf.** Bass boost is a low-shelf at 150 Hz with up to +12 dB. It is not a multiband EQ.
- **No mastering chain.** The limiter prevents clipping but the app is not a replacement for a DAW.
- **Single source track.** There is no multi-track timeline, track mixing, or recording.

## Uploads and files

- **100 MB / 30 minutes by default.** Limits are configurable but must stay aligned with PHP and web-server limits.
- **Queue required for large files.** Files above `AUDIO_LARGE_UPLOAD_THRESHOLD_MB` stay in `processing` until a worker runs.
- **Local private disk only.** The shipped configuration does not use S3/R2 or another object store.
- **No resumable uploads.** A dropped connection restarts the upload.
- **No duplicate detection.** Uploading the same file twice creates two library entries.
- **Filename display sanitization.** The original name is preserved for display/download, but unsafe characters are replaced in download basenames and storage paths are always UUID-based.

## Accounts and product scope

- **No email verification.** Registration creates an immediately usable account.
- **No password reset UI in this build.** Laravel's password broker is available, but the product UI focuses on the studio.
- **No OAuth/social login.**
- **No sharing or public links.** Every file is private to its owner.
- **No collaboration or real-time multi-user editing.**
- **No mobile native app.** The responsive Blade UI works in modern mobile browsers.

## Exports and retention

- **Exports are temporary.** `AUDIO_OUTPUT_RETENTION_HOURS` defaults to 24 hours; downloads expire after cleanup.
- **One active export per file/format/settings combination.** Repeating the same request while a job is active reuses it instead of creating duplicates.
- **WAV and MP3 only.** FLAC, AAC and OGG are not exposed as export formats even if FFmpeg supports them.
- **No background job progress streaming.** The UI polls the status endpoint; it does not use WebSockets.
- **Queue workers keep old code in memory.** Restart `queue:work` after deploying PHP changes, or use `queue:listen` in development.

## Deployment and environment

- **MySQL 8+ is the target database.** SQLite is supported for local development and was used for local smoke tests because MySQL was not installed on the development machine.
- **FFmpeg licensing depends on the build.** Review the license of the FFmpeg binary you deploy, especially GPL-enabled builds.
- **External CDNs.** Inter and Font Awesome are loaded from CDNs by default; offline deployments should self-host them.
- **No built-in observability stack.** Use Laravel logs, Supervisor logs, and your platform's metrics. There is no Prometheus/Grafana integration.
- **No horizontal file sharing.** Multiple application servers need shared storage or an object-store refactor; local private disks are node-specific.

## Browser support

The UI targets current Chrome, Edge, Firefox and Safari. Older browsers may lack AudioWorklet, `preservesPitch`, or modern ES module support. In those cases playback and server exports still work, but some live preview effects may be unavailable.
