# Dependencies and licenses

This project is a Laravel application. The exact resolved versions are pinned in `composer.lock` and `package-lock.json`.

## Runtime / backend

| Dependency | Version used locally | Purpose | License |
| --- | --- | --- | --- |
| PHP | 8.5.10 (tested) | Runtime | PHP License 3.01 |
| Laravel Framework | 12.70.2 | Application framework | MIT |
| Symfony Process | Laravel dependency | Shell-free FFmpeg execution | MIT |
| Flysystem AWS S3 adapter | 3.35.3 | Private S3/R2 storage for serverless deployments | MIT |
| AWS SDK for PHP | 3.402.2 | Presigned direct-to-bucket uploads | Apache-2.0 |
| Composer | 2.10.3 | PHP dependency manager | MIT |

The application requires PHP 8.3 or newer. Composer is pinned to a PHP 8.3 platform so the lock file stays installable on the Vercel container. The local development machine used PHP 8.5.

Install with:

```bash
composer install
```

## Frontend

| Dependency | Version used locally | Purpose | License |
| --- | --- | --- | --- |
| Bootstrap | 5.3.8 | UI framework and modal/dropdown behavior | MIT |
| Popper.js (`@popperjs/core`) | 2.11.8 | Bootstrap positioning | MIT |
| WaveSurfer.js | 7.12.12 | Waveform rendering, seeking and playback | BSD-3-Clause |
| Vite | 7.3.7 | Asset bundling / dev server | MIT |
| `laravel-vite-plugin` | 2.1.0 | Laravel + Vite integration | MIT |
| Axios | 1.21.0 | Present as a default Laravel dependency; the studio uses native `fetch` | MIT |
| Inter | Loaded from Google Fonts / fallback stack | UI font | SIL Open Font License 1.1 |
| Font Awesome Free 6 | CDN | Icons | Icons: CC BY 4.0; Fonts: SIL OFL 1.1; Code: MIT |

Install and build with:

```bash
npm install
npm run build
```

`puppeteer-core` is **not** an application dependency. It was used only for local browser smoke testing and is not listed in `package.json`.

## Audio tooling

| Tool | Version used locally | Purpose | License note |
| --- | --- | --- | --- |
| FFmpeg | 8.1.1 | Decoding, resampling, effects, encoding | FFmpeg is LGPL 2.1+ by default. Builds with `--enable-gpl` and GPL-only components are GPL. Check your exact build. |
| FFprobe | 8.1.1 | Media metadata probing | Same FFmpeg license as the build |
| libmp3lame | bundled in local FFmpeg build | MP3 encoding | LGPL 2.0 |
| Nginx | Debian bookworm package | HTTP server in the Vercel container | BSD-2-Clause |
| PHP-FPM | Debian PHP image | Application server in the Vercel container | PHP License 3.01 |

The application calls FFmpeg as an external program. It does not link FFmpeg into PHP. Confirm the license of the FFmpeg binary you deploy, especially if you distribute a modified build or enable GPL-only filters.

## Browser APIs

- **WaveSurfer.js** uses Canvas and the Web Audio API.
- **AudioWorklet** is used for the optional independent pitch preview (`public/js/pitch-shift-worklet.js`).
- **`HTMLMediaElement.preservesPitch`** is used for tape-mode preview.
- If a browser lacks AudioWorklet, independent pitch preview falls back to a warning; server exports still apply pitch correctly.

## Fonts and icons

- Inter is loaded via Google Fonts in the Blade layout. For offline/self-hosted deployments, download the font files and update the stylesheet link.
- Font Awesome Free is loaded from the jsDelivr CDN. For offline deployments, self-host the CSS and webfonts.

## License compliance checklist

- [x] `composer.lock` committed for reproducible PHP dependencies
- [x] `package-lock.json` committed for reproducible frontend dependencies
- [x] Third-party license table documented
- [x] FFmpeg build/license caveat documented
- [x] No third-party code copied into the repository without attribution
- [ ] Review the deployed FFmpeg build's exact license and configure it for your distribution model
- [ ] Self-host fonts/icons if the deployment must work without external CDNs
