<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'demo@audio.test'],
            [
                'name' => 'Demo Producer',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );
    }
}
