<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Level;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class LevelPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Level');
    }

    public function view(AuthUser $authUser, Level $level): bool
    {
        return $authUser->can('View:Level');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Level');
    }

    public function update(AuthUser $authUser, Level $level): bool
    {
        return $authUser->can('Update:Level');
    }

    public function delete(AuthUser $authUser, Level $level): bool
    {
        return $authUser->can('Delete:Level');
    }
}
