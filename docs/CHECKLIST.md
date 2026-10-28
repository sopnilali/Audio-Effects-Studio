# Project checklist

## Core application

- [x] Laravel 12 project scaffolded and configured
- [x] Authentication: register, login, logout, session handling
- [x] Per-user private audio library
- [x] Ownership policies for files and processing jobs
- [x] Blade studio layout matching the purple reference design
- [x] Responsive mobile/desktop layout
- [x] Bootstrap 5 modals, dropdowns and toasts loaded through Vite
- [x] Font Awesome 6 icons and Inter font
- [x] Empty states, loading states and error states
- [x] Search/filter in the file list
- [x] Rename file with Unicode support
- [x] Delete file with confirmation modal
- [x] Clear workspace with confirmation modal

## Upload and preparation

- [x] Multi-file upload with progress
- [x] Drag-and-drop upload
- [x] Validation for MIME type, extension, size, count, duration and quota
- [x] Bengali/Unicode filename support
- [x] UUID-based private storage paths
- [x] FFprobe metadata extraction
- [x] Browser-safe MP3 preview generation
- [x] Real server-side waveform peak extraction
- [x] Synchronous preparation for small files
- [x] Queued preparation for large files
- [x] Status polling from `processing` to `ready`
- [x] Upload limits raised for `php artisan serve`
- [x] `public/.user.ini` for PHP-FPM/Apache
- [x] Documentation for Nginx/Apache/PHP limits

## Playback and waveform

- [x] WaveSurfer waveform rendering from real peaks
- [x] Seeking by clicking/dragging the waveform
- [x] Play/pause, previous/next
- [x] Volume and mute controls
- [x] Keyboard shortcuts
- [x] HTTP range streaming of private previews
- [x] Selected-file metadata panel
- [x] Mobile player layout

## Effects

- [x] Speed control (0.25x–2.00x)
- [x] Tape mode: slow = deeper, fast = thinner
- [x] Toggle to preserve pitch while changing speed
- [x] Independent pitch control (0.50x–2.00x)
- [x] Pitch/speed preview through Web Audio and AudioWorklet
- [x] Reverb with dry/wet mix and generated impulse
- [x] Bass boost with low-shelf filter
- [x] Presets: Original, Pure Reverb, Slowed + Reverb, Nightcore
- [x] Effect values clamped server-side
- [x] Debounced effect saving
- [x] Effective duration display
- [x] Total semitone display
- [x] Reset individual effects and full reset

## Export and processing

- [x] Queued WAV export (`pcm_s16le`)
- [x] Queued MP3 export (`libmp3lame`)
- [x] Synchronous export option for local demos
- [x] Export progress polling
- [x] Export cancellation for pending jobs
- [x] Duplicate active export reuse
- [x] Output verification (exists, non-empty, codec, duration)
- [x] Protected download route
- [x] Unicode download filename with ASCII fallback
- [x] Export retention cleanup
- [x] Temporary-file cleanup job and scheduler
- [x] Queue worker documentation

## Security and reliability

- [x] CSRF protection on state-changing routes
- [x] Rate limiting on upload/export/status routes
- [x] FormRequest validation
- [x] Authorization policies and cross-user tests
- [x] Private disk storage
- [x] No shell interpolation; Symfony Process argument arrays only
- [x] `X-Content-Type-Options: nosniff` on downloads
- [x] CSRF/session/database queue configuration documented
- [x] Error handling and user-safe failure messages
- [x] Real FFmpeg integration tests
- [x] Feature tests for upload, auth, effects, exports, deletion and ownership
- [x] Unit tests for normalization, filter graphs, model state and Unicode names
- [x] `php artisan audio:check` environment command

## Documentation

- [x] Main README with install, MySQL, FFmpeg and queue instructions
- [x] `.env.example` with documented settings
- [x] Deployment guide
- [x] Dependency/license document
- [x] Known limitations document
- [x] Completed/uncompleted checklist (this file)
- [x] Troubleshooting section

## Deliberately not included

- [ ] Multi-track editor / mixer
- [ ] Microphone recording
- [ ] Custom reverb impulse uploads
- [ ] Formant-preserving pitch shifting
- [ ] OAuth/social login and email verification
- [ ] Public share links
- [ ] S3/R2/object-storage driver
- [ ] WebSocket progress streaming
- [ ] Native mobile apps
- [ ] Prometheus/Grafana monitoring integration
- [ ] Offline self-hosting of Inter/Font Awesome by default
