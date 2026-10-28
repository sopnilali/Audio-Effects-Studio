<?php

use App\Http\Controllers\AudioController;
use App\Http\Controllers\AudioEffectController;
use App\Http\Controllers\AudioExportController;
use App\Http\Controllers\AudioProcessingController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('audio.index')
        : redirect()->route('login');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('login.attempt');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1')
        ->name('register.attempt');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/audio', [AudioController::class, 'index'])->name('audio.index');

    Route::post('/audio/upload', [AudioController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('audio.upload');

    Route::post('/audio/upload/presign', [AudioController::class, 'presign'])
        ->middleware('throttle:60,1')
        ->name('audio.upload.presign');

    Route::post('/audio/upload/complete', [AudioController::class, 'complete'])
        ->middleware('throttle:60,1')
        ->name('audio.upload.complete');

    Route::delete('/audio', [AudioController::class, 'clear'])
        ->middleware('throttle:10,1')
        ->name('audio.clear');

    Route::get('/audio/{audioFile}', [AudioController::class, 'show'])->name('audio.show');
    Route::get('/audio/{audioFile}/stream', [AudioController::class, 'stream'])
        ->middleware('throttle:240,1')
        ->name('audio.stream');
    Route::get('/audio/{audioFile}/metadata', [AudioController::class, 'metadata'])->name('audio.metadata');

    Route::patch('/audio/{audioFile}/effects', [AudioEffectController::class, 'update'])
        ->middleware('throttle:120,1')
        ->name('audio.effects.update');

    Route::patch('/audio/{audioFile}/rename', [AudioController::class, 'rename'])
        ->middleware('throttle:30,1')
        ->name('audio.rename');

    Route::delete('/audio/{audioFile}', [AudioController::class, 'destroy'])
        ->middleware('throttle:30,1')
        ->name('audio.destroy');

    Route::post('/audio/{audioFile}/export', [AudioExportController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('audio.export.store');

    Route::get('/audio-processing/{job}/status', [AudioProcessingController::class, 'status'])
        ->middleware('throttle:240,1')
        ->name('audio.processing.status');

    Route::get('/audio-processing/{job}/download', [AudioProcessingController::class, 'download'])
        ->middleware('throttle:30,1')
        ->name('audio.processing.download');

    Route::post('/audio-processing/{job}/cancel', [AudioProcessingController::class, 'cancel'])
        ->middleware('throttle:30,1')
        ->name('audio.processing.cancel');
});
