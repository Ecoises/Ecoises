<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Announcement;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AnnouncementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $user): bool
    {
        return $user->can('ViewAny:Announcement');
    }

    public function view(AuthUser $user, Announcement $announcement): bool
    {
        return $user->can('View:Announcement');
    }

    public function create(AuthUser $user): bool
    {
        return $user->can('Create:Announcement');
    }

    public function update(AuthUser $user, Announcement $announcement): bool
    {
        return $user->can('Update:Announcement');
    }

    public function delete(AuthUser $user, Announcement $announcement): bool
    {
        return $user->can('Delete:Announcement');
    }
}
