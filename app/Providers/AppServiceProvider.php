<?php

namespace App\Providers;

use App\Services\Audio\FfmpegRunner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FfmpegRunner::class, fn (): FfmpegRunner => FfmpegRunner::fromConfig());
    }

    public function boot(): void
    {
        //
    }
}
