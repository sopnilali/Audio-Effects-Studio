<?php

use App\Exceptions\AudioProcessingException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AudioProcessingException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->safeMessage(),
                    'errors' => ['audio' => [$exception->safeMessage()]],
                ], $exception->httpStatus);
            }

            return back()
                ->withInput()
                ->withErrors(['audio' => $exception->safeMessage()]);
        });
    })->create();

if ($storagePath = env('LARAVEL_STORAGE_PATH')) {
    $storagePath = (string) $storagePath;

    if (! is_dir($storagePath)) {
        @mkdir($storagePath, 0775, true);
    }

    $app->useStoragePath($storagePath);
}

return $app;
