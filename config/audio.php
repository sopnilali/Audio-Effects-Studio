<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | These values must stay in sync with PHP's upload_max_filesize and
    | post_max_size settings. The FormRequest enforces the limits again.
    |
    */
    'max_upload_mb' => (int) env('AUDIO_MAX_UPLOAD_MB', 100),
    'max_files_per_upload' => (int) env('AUDIO_MAX_FILES_PER_UPLOAD', 10),
    'max_files_per_user' => (int) env('AUDIO_MAX_FILES_PER_USER', 100),
    'max_duration_seconds' => (int) env('AUDIO_MAX_DURATION_SECONDS', 1800),
    'storage_quota_mb' => (int) env('AUDIO_STORAGE_QUOTA_MB', 2048),

    /*
    |--------------------------------------------------------------------------
    | Supported input formats
    |--------------------------------------------------------------------------
    */
    'supported_extensions' => ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'],

    'supported_mime_types' => [
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'audio/vnd.wave',
        'audio/ogg',
        'audio/x-ogg',
        'audio/mp4',
        'audio/x-m4a',
        'audio/aac',
        'audio/x-aac',
        'audio/flac',
        'audio/x-flac',
    ],

    /*
    |--------------------------------------------------------------------------
    | Export settings
    |--------------------------------------------------------------------------
    */
    'export_formats' => ['wav', 'mp3'],
    'mp3_bitrate' => (int) env('AUDIO_MP3_BITRATE', 192000),
    'preview_bitrate' => (int) env('AUDIO_PREVIEW_BITRATE', 128000),

    /*
    |--------------------------------------------------------------------------
    | Background processing
    |--------------------------------------------------------------------------
    |
    | Uploads larger than the threshold are prepared by a queued job. Exports
    | are always queued unless AUDIO_SYNC_EXPORTS is enabled for a small local
    | installation (the request then blocks until the export completes).
    |
    */
    'large_upload_threshold_bytes' => (int) env('AUDIO_LARGE_UPLOAD_THRESHOLD_MB', 25) * 1024 * 1024,
    'sync_exports' => (bool) env('AUDIO_SYNC_EXPORTS', false),
    'job_timeout' => (int) env('AUDIO_JOB_TIMEOUT', 900),
    'max_concurrent_jobs_per_user' => (int) env('AUDIO_MAX_CONCURRENT_JOBS', 2),

    /*
    |--------------------------------------------------------------------------
    | Retention / cleanup
    |--------------------------------------------------------------------------
    */
    'temp_retention_hours' => (int) env('AUDIO_TEMP_RETENTION_HOURS', 24),
    'output_retention_hours' => (int) env('AUDIO_OUTPUT_RETENTION_HOURS', 24),
    'trash_retention_hours' => (int) env('AUDIO_TRASH_RETENTION_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Binaries
    |--------------------------------------------------------------------------
    |
    | Leave the paths empty to resolve the binaries from PATH. Arguments are
    | always passed to Symfony Process as an array, never through a shell.
    |
    */
    'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg') ?: 'ffmpeg',
    'ffprobe_path' => env('FFPROBE_PATH', 'ffprobe') ?: 'ffprobe',

    /*
    |--------------------------------------------------------------------------
    | Audio algorithm settings
    |--------------------------------------------------------------------------
    */
    'reverb_impulse_path' => 'audio/impulse/studio-hall.wav',
    'reverb_impulse_seconds' => 2.4,
    'reverb_decay' => 3.2,
    'bass_shelf_frequency' => 150,
    'bass_shelf_max_gain_db' => 12,
    'limiter_level' => 0.95,
    'waveform_peaks' => (int) env('AUDIO_WAVEFORM_PEAKS', 720),
    'waveform_sample_rate' => 4000,

    /*
    |--------------------------------------------------------------------------
    | Player / UI defaults
    |--------------------------------------------------------------------------
    */
    'default_effects' => [
        'speed' => 1.0,
        'pitch_follows_speed' => true,
        'pitch_enabled' => false,
        'pitch_multiplier' => 1.0,
        'reverb' => 0,
        'bass_boost' => 0,
        'preset_name' => null,
    ],

    'presets' => [
        'pure_reverb' => [
            'label' => 'Pure Reverb',
            'description' => 'Original speed and pitch with a 40% hall reverb tail',
            'settings' => [
                'speed' => 1.0,
                'pitch_follows_speed' => true,
                'pitch_enabled' => false,
                'pitch_multiplier' => 1.0,
                'reverb' => 40,
                'bass_boost' => 0,
                'preset_name' => 'pure_reverb',
            ],
        ],
        'slowed_reverb' => [
            'label' => 'Slowed + Reverb',
            'description' => '0.8x tape speed with a deeper voice and a wide 40% reverb tail',
            'settings' => [
                'speed' => 0.8,
                'pitch_follows_speed' => true,
                'pitch_enabled' => false,
                'pitch_multiplier' => 1.0,
                'reverb' => 40,
                'bass_boost' => 0,
                'preset_name' => 'slowed_reverb',
            ],
        ],
        'nightcore' => [
            'label' => 'Nightcore',
            'description' => '1.25x tape speed with a brighter, thinner voice (about +4 semitones)',
            'settings' => [
                'speed' => 1.25,
                'pitch_follows_speed' => true,
                'pitch_enabled' => false,
                'pitch_multiplier' => 1.0,
                'reverb' => 0,
                'bass_boost' => 0,
                'preset_name' => 'nightcore',
            ],
        ],
    ],

    'speed' => ['min' => 0.25, 'max' => 2.0, 'step' => 0.05],
    'pitch' => ['min' => 0.5, 'max' => 2.0, 'step' => 0.01],
    'reverb' => ['min' => 0, 'max' => 100, 'step' => 1],
    'bass_boost' => ['min' => 0, 'max' => 100, 'step' => 1],
];
