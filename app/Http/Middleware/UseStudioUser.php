<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The studio is a single-user application. A hidden account owns the library
 * so the existing ownership policies and database relationships keep working
 * without exposing a login or registration flow.
 */
class UseStudioUser
{
    public const EMAIL = 'studio@audio.local';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            $user = User::query()->firstOrCreate(
                ['email' => self::EMAIL],
                [
                    'name' => 'Studio',
                    'password' => Str::random(40),
                    'email_verified_at' => now(),
                ],
            );

            Auth::setUser($user);
            $request->setUserResolver(fn (): User => $user);
        }

        return $next($request);
    }
}
