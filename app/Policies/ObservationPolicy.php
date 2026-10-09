<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Observation;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ObservationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Observation');
    }

    public function view(AuthUser $authUser, Observation $observation): bool
    {
        return $authUser->can('View:Observation');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Observation');
    }

    public function update(AuthUser $authUser, Observation $observation): bool
    {
        return $authUser->can('Update:Observation');
    }

    public function delete(AuthUser $authUser, Observation $observation): bool
    {
        return $authUser->can('Delete:Observation');
    }
}
