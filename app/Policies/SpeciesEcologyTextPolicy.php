<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SpeciesEcologyText;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SpeciesEcologyTextPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SpeciesEcologyText');
    }

    public function view(AuthUser $authUser, SpeciesEcologyText $speciesEcologyText): bool
    {
        return $authUser->can('View:SpeciesEcologyText');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SpeciesEcologyText');
    }

    public function update(AuthUser $authUser, SpeciesEcologyText $speciesEcologyText): bool
    {
        return $authUser->can('Update:SpeciesEcologyText');
    }

    public function delete(AuthUser $authUser, SpeciesEcologyText $speciesEcologyText): bool
    {
        return $authUser->can('Delete:SpeciesEcologyText');
    }
}
