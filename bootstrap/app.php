<?php

use App\Exceptions\AudioProcessingException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
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
