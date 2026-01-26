<?php

namespace App\Policies;

use App\Models\Tool;
use App\Models\User;

class ToolPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Tool $tool): bool
    {
        return $user->id === $tool->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Tool $tool): bool
    {
        return $user->id === $tool->user_id;
    }

    public function delete(User $user, Tool $tool): bool
    {
        return $user->id === $tool->user_id;
    }

    public function restore(User $user, Tool $tool): bool
    {
        return $user->id === $tool->user_id;
    }

    public function forceDelete(User $user, Tool $tool): bool
    {
        return $user->id === $tool->user_id;
    }
}
