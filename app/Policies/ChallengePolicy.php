<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Challenge;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ChallengePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Challenge');
    }

    public function view(AuthUser $authUser, Challenge $challenge): bool
    {
        return $authUser->can('View:Challenge');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Challenge');
    }

    public function update(AuthUser $authUser, Challenge $challenge): bool
    {
        return $authUser->can('Update:Challenge');
    }

    public function delete(AuthUser $authUser, Challenge $challenge): bool
    {
        return $authUser->can('Delete:Challenge');
    }
}
