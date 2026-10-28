<?php

use App\Http\Middleware\UseStudioUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Consolidate any library created before single-user mode into the hidden
     * studio account so existing uploads and exports remain reachable.
     */
    public function up(): void
    {
        $now = now();

        $userId = DB::table('users')->where('email', UseStudioUser::EMAIL)->value('id');

        if (! $userId) {
            $userId = DB::table('users')->insertGetId([
                'name' => 'Studio',
                'email' => UseStudioUser::EMAIL,
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('audio_files')
            ->where(fn ($query) => $query->where('user_id', '!=', $userId)->orWhereNull('user_id'))
            ->update(['user_id' => $userId, 'updated_at' => $now]);

        DB::table('audio_processing_jobs')
            ->where(fn ($query) => $query->where('user_id', '!=', $userId)->orWhereNull('user_id'))
            ->update(['user_id' => $userId, 'updated_at' => $now]);
    }

    public function down(): void
    {
        // Ownership consolidation is intentionally irreversible.
    }
};
