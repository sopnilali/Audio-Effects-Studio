<footer class="player-bar" id="playerBar" aria-label="Audio player">
    <div class="player-track">
        <span class="player-art" aria-hidden="true"><i class="fa-solid fa-music"></i></span>
        <div class="player-track-copy">
            <div class="player-title" id="playerTitle">{{ $selectedFile?->display_name ?? 'No track selected' }}</div>
            <div class="player-subtitle" id="playerSubtitle">
                {{ $selectedFile ? strtoupper($selectedFile->extension).' · '.$selectedFile->formattedDuration() : 'Upload or select a track' }}
            </div>
        </div>
    </div>

    <div class="player-center">
        <div class="player-controls">
            <button class="icon-button" type="button" id="previousButton" aria-label="Previous track" title="Previous track" @disabled($audioFiles->count() < 2)>
                <i class="fa-solid fa-backward-step" aria-hidden="true"></i>
            </button>

            <button class="icon-button play-button" type="button" id="playPauseButton" aria-label="Play" title="Play (Space)" @disabled(! $selectedFile || ! $selectedFile->isReady())>
                <i class="fa-solid fa-play" id="playPauseIcon" aria-hidden="true"></i>
            </button>

            <button class="icon-button" type="button" id="nextButton" aria-label="Next track" title="Next track" @disabled($audioFiles->count() < 2)>
                <i class="fa-solid fa-forward-step" aria-hidden="true"></i>
            </button>

            <button class="icon-button" type="button" id="repeatButton" aria-label="Repeat track" aria-pressed="false" title="Repeat (R)">
                <i class="fa-solid fa-repeat" aria-hidden="true"></i>
            </button>

            <span class="player-error" id="playerError" role="alert">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <span>Playback unavailable</span>
            </span>
        </div>

        <div class="player-progress">
            <span class="time current" id="playerCurrentTime">0:00</span>
            <label class="visually-hidden" for="playerProgress">Seek</label>
            <input class="studio-range" id="playerProgress" type="range" min="0" max="100" step="0.1" value="0" style="--range-progress: 0%" disabled>
            <span class="time" id="playerTotalTime">{{ $selectedFile?->formattedDuration() ?? '0:00' }}</span>
        </div>
    </div>

    <div class="player-right">
        <div class="volume-control">
            <button class="icon-button" type="button" id="muteButton" aria-label="Mute" title="Mute" style="width: 30px; height: 30px;">
                <i class="fa-solid fa-volume-high" id="volumeIcon" aria-hidden="true"></i>
            </button>
            <label class="visually-hidden" for="volumeSlider">Volume</label>
            <input class="studio-range" id="volumeSlider" type="range" min="0" max="100" step="1" value="80" style="--range-progress: 80%">
        </div>
    </div>

    <audio id="studioAudio" preload="metadata" playsinline></audio>
</footer>
