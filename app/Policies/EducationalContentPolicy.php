<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EducationalContent;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class EducationalContentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EducationalContent');
    }

    public function view(AuthUser $authUser, EducationalContent $educationalContent): bool
    {
        return $authUser->can('View:EducationalContent')
            && (! $authUser->hasRole('educador') || $educationalContent->author_id === $authUser->getKey());
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EducationalContent');
    }

    public function update(AuthUser $authUser, EducationalContent $educationalContent): bool
    {
        if (! $authUser->can('Update:EducationalContent')) {
            return false;
        }

        if (! $authUser->hasRole('educador')) {
            return true;
        }

        return $educationalContent->author_id === $authUser->getKey()
            && $educationalContent->status === EducationalContent::STATUS_DRAFT;
    }

    public function delete(AuthUser $authUser, EducationalContent $educationalContent): bool
    {
        return $authUser->can('Delete:EducationalContent')
            && (! $authUser->hasRole('educador') || (
                $educationalContent->author_id === $authUser->getKey()
                && $educationalContent->status === EducationalContent::STATUS_DRAFT
            ));
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
