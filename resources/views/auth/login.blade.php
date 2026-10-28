@extends('layouts.app')

@section('title', 'Sign in — Audio Effects Studio')
@section('body-class', 'auth-body')

@section('content')
<div class="auth-shell">
    <section class="auth-showcase">
        <a class="brand" href="{{ route('home') }}">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round">
                    <path d="M4 10v4M8 7v10M12 4v16M16 7v10M20 10v4"/>
                </svg>
            </span>
            <span class="brand-copy">
                <span class="brand-title">Audio Effects Studio</span>
                <span class="brand-subtitle">Shape the sound</span>
            </span>
        </a>

        <div class="auth-hero">
            <h1>Your audio,<br><span>reimagined.</span></h1>
            <p>Upload a track, slow it down, lift the pitch, add a cavernous reverb or a warm bass boost — then export a real processed WAV or MP3.</p>

            <div class="auth-feature-list">
                <div class="auth-feature"><i class="fa-solid fa-wave-square" aria-hidden="true"></i> Real waveforms and seekable playback</div>
                <div class="auth-feature"><i class="fa-solid fa-sliders" aria-hidden="true"></i> Independent speed and pitch controls</div>
                <div class="auth-feature"><i class="fa-solid fa-download" aria-hidden="true"></i> Genuine FFmpeg WAV and MP3 exports</div>
            </div>
        </div>

        <div class="auth-wave" aria-hidden="true">
            @foreach ([18, 34, 26, 48, 62, 40, 72, 54, 30, 66, 44, 76, 52, 28, 60, 38, 70, 46, 24, 56, 36, 64, 42, 20] as $height)
                <span style="height: {{ $height }}%"></span>
            @endforeach
        </div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <h2>Welcome back</h2>
            <p class="auth-intro">Sign in to open your private audio library and studio.</p>

            @if ($errors->any())
                <div class="alert-studio" role="alert">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" novalidate>
                @csrf

                <div class="mb-3">
                    <label class="form-label" for="email">Email address</label>
                    <input class="form-control @error('email') is-invalid @enderror"
                           id="email"
                           type="email"
                           name="email"
                           value="{{ old('email') }}"
                           autocomplete="email"
                           inputmode="email"
                           required
                           autofocus>
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control @error('password') is-invalid @enderror"
                           id="password"
                           type="password"
                           name="password"
                           autocomplete="current-password"
                           required>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1" @checked(old('remember'))>
                    <label class="form-check-label" for="remember">Keep me signed in</label>
                </div>

                <button class="btn btn-studio-primary auth-submit" type="submit">
                    Sign in <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>
                </button>
            </form>

            <p class="auth-switch">
                New here? <a href="{{ route('register') }}">Create an account</a>
            </p>

            @if (app()->environment('local'))
                <div class="auth-demo">
                    Demo account: <code>demo@audio.test</code> / <code>password</code>
                </div>
            @endif
        </div>
    </section>
</div>
@endsection
