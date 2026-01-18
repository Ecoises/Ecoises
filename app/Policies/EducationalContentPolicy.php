<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\EducationalContent;
use Illuminate\Auth\Access\HandlesAuthorization;

class EducationalContentPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EducationalContent');
    }

    public function view(AuthUser $authUser, EducationalContent $educationalContent): bool
    {
        return $authUser->can('View:EducationalContent');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EducationalContent');
    }

    public function update(AuthUser $authUser, EducationalContent $educationalContent): bool
    {
        return $authUser->can('Update:EducationalContent');
    }

    public function delete(AuthUser $authUser, EducationalContent $educationalContent): bool
    {
        return $authUser->can('Delete:EducationalContent');
    }

    public function restore(AuthUser $authUser, EducationalContent $educationalContent): bool
    {
        return $authUser->can('Restore:EducationalContent');
    }

    public function forceDelete(AuthUser $authUser, EducationalContent $educationalContent): bool
    {
        return $authUser->can('ForceDelete:EducationalContent');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:EducationalContent');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:EducationalContent');
    }

    public function replicate(AuthUser $authUser, EducationalContent $educationalContent): bool
    {
        return $authUser->can('Replicate:EducationalContent');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:EducationalContent');
    }

}