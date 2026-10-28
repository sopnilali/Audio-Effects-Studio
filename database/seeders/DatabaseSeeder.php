<?php

namespace Database\Seeders;

use App\Http\Middleware\UseStudioUser;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => UseStudioUser::EMAIL],
            [
                'name' => 'Studio',
                'password' => Str::random(40),
                'email_verified_at' => now(),
            ],
        );
    }
}
