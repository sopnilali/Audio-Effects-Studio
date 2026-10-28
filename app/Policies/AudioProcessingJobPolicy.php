<?php

namespace App\Policies;

use App\Models\AudioProcessingJob;
use App\Models\User;

class AudioProcessingJobPolicy
{
    public function view(User $user, AudioProcessingJob $job): bool
    {
        return $job->user_id === $user->id;
    }

    public function download(User $user, AudioProcessingJob $job): bool
    {
        return $this->view($user, $job);
    }

    public function cancel(User $user, AudioProcessingJob $job): bool
    {
        return $this->view($user, $job);
    }
}
