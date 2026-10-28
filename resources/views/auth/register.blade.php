@extends('layouts.app')

@section('title', 'Create an account — Audio Effects Studio')
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
            <h1>Make it<br><span>yours.</span></h1>
            <p>Create a private studio library. Your uploads stay behind authenticated routes and are never exposed as public files.</p>

            <div class="auth-feature-list">
                <div class="auth-feature"><i class="fa-solid fa-lock" aria-hidden="true"></i> Private, per-user audio storage</div>
                <div class="auth-feature"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Pure Reverb, Slowed + Reverb and Nightcore presets</div>
                <div class="auth-feature"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Per-file effect settings that persist</div>
            </div>
        </div>

        <div class="auth-wave" aria-hidden="true">
            @foreach ([22, 42, 30, 58, 38, 68, 50, 76, 34, 62, 46, 72, 40, 54, 28, 66, 48, 74, 36, 60, 44, 70, 32, 52] as $height)
                <span style="height: {{ $height }}%"></span>
            @endforeach
        </div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <h2>Create your studio</h2>
            <p class="auth-intro">A few details and your private audio workspace is ready.</p>

            @if ($errors->any())
                <div class="alert-studio" role="alert">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('register.attempt') }}" novalidate>
                @csrf

                <div class="mb-3">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control @error('name') is-invalid @enderror"
                           id="name"
                           type="text"
                           name="name"
                           value="{{ old('name') }}"
                           autocomplete="name"
                           maxlength="100"
                           required
                           autofocus>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="email">Email address</label>
                    <input class="form-control @error('email') is-invalid @enderror"
                           id="email"
                           type="email"
                           name="email"
                           value="{{ old('email') }}"
                           autocomplete="email"
                           inputmode="email"
                           required>
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
                           autocomplete="new-password"
                           minlength="8"
                           required>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="effect-sub mt-1">At least 8 characters with letters and numbers.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password_confirmation">Confirm password</label>
                    <input class="form-control"
                           id="password_confirmation"
                           type="password"
                           name="password_confirmation"
                           autocomplete="new-password"
                           minlength="8"
                           required>
                </div>

                <button class="btn btn-studio-primary auth-submit" type="submit">
                    Create account <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>
                </button>
            </form>

            <p class="auth-switch">
                Already have an account? <a href="{{ route('login') }}">Sign in</a>
            </p>
        </div>
    </section>
</div>
@endsection
