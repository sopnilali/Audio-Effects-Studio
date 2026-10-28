<?php

namespace App\Policies;

use App\Models\AudioFile;
use App\Models\User;

class AudioFilePolicy
{
    public function view(User $user, AudioFile $audioFile): bool
    {
        return $audioFile->user_id === $user->id;
    }

    public function update(User $user, AudioFile $audioFile): bool
    {
        return $this->view($user, $audioFile);
    }

    public function delete(User $user, AudioFile $audioFile): bool
    {
        return $this->view($user, $audioFile);
    }

    public function stream(User $user, AudioFile $audioFile): bool
    {
        return $this->view($user, $audioFile);
    }

    public function export(User $user, AudioFile $audioFile): bool
    {
        return $this->view($user, $audioFile);
    }
}
