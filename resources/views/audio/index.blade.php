@extends('layouts.app')

@section('title', $selectedFile ? $selectedFile->display_name.' — Audio Effects Studio' : 'Audio Effects Studio')
@section('body-class', 'studio-body')

@section('content')
<script type="application/json" id="studioData">{!! json_encode($studio, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
<script type="application/json" id="flashData">{!! json_encode([
    'status' => session('status'),
    'error' => session('error'),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

<div class="studio-shell" id="studioShell" data-mobile-view="files">
    @include('audio.partials.toolbar')

    <main class="studio-main">
        @include('audio.partials.file-list')
        @include('audio.editor')
    </main>

    @include('audio.partials.bottom-player')
</div>

@include('audio.partials.export-modal')

<div class="modal fade" id="renameModal" tabindex="-1" aria-labelledby="renameModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="renameModalLabel">Rename audio file</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="renameForm" novalidate>
                <div class="modal-body">
                    <label class="form-label" for="renameInput">Display name</label>
                    <input class="form-control" id="renameInput" name="display_name" type="text" maxlength="200" required autocomplete="off">
                    <div class="effect-sub mt-1">The original extension is kept automatically.</div>
                    <div class="export-error mt-2" id="renameError" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-studio-ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-studio-primary" id="renameSaveButton">
                        <i class="fa-solid fa-check me-1" aria-hidden="true"></i> Save name
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="confirmModalLabel">Confirm action</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="confirm-copy" id="confirmMessage">Are you sure?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-studio-ghost" data-bs-dismiss="modal" id="confirmCancelButton">Cancel</button>
                <button type="button" class="btn btn-studio-primary" id="confirmActionButton">Confirm</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="shortcutsModal" tabindex="-1" aria-labelledby="shortcutsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="shortcutsModalLabel">Keyboard shortcuts</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="export-summary mt-0">
                    <dl>
                        <dt><kbd>Space</kbd></dt><dd>Play / pause</dd>
                        <dt><kbd>←</kbd> <kbd>→</kbd></dt><dd>Seek 5 seconds</dd>
                        <dt><kbd>↑</kbd> <kbd>↓</kbd></dt><dd>Volume up / down</dd>
                        <dt><kbd>R</kbd></dt><dd>Toggle repeat</dd>
                        <dt><kbd>N</kbd></dt><dd>Next track</dd>
                        <dt><kbd>P</kbd></dt><dd>Previous track</dd>
                    </dl>
                </div>
                <p class="text-studio-muted mb-0 mt-3" style="font-size: 0.68rem;">Shortcuts are ignored while typing in a field.</p>
            </div>
        </div>
    </div>
</div>
@endsection
