<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('display_name');
            $table->string('storage_path', 1024);
            $table->string('preview_path', 1024)->nullable();
            $table->string('mime_type', 120);
            $table->string('extension', 12);
            $table->unsignedBigInteger('file_size');
            $table->decimal('duration_seconds', 12, 3)->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedTinyInteger('channels')->nullable();
            $table->unsignedInteger('bit_rate')->nullable();
            $table->string('codec', 60)->nullable();
            $table->string('container', 120)->nullable();
            $table->string('status', 24)->default('uploaded');
            $table->text('error_message')->nullable();
            $table->json('effects')->nullable();
            $table->json('waveform')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('audio_processing_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_file_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('job_type', 24)->default('export');
            $table->string('input_path', 1024);
            $table->string('output_path', 1024)->nullable();
            $table->string('format', 12)->nullable();
            $table->json('settings')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['audio_file_id', 'job_type', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_processing_jobs');
        Schema::dropIfExists('audio_files');
    }
};
