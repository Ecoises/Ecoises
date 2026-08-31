<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;

class ReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Report');
    }

    public function view(User $user, Report $report): bool
    {
        return $user->can('View:Report');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Report $report): bool
    {
        return $user->can('Update:Report');
    }

    public function delete(User $user, Report $report): bool
    {
        return $user->can('Delete:Report');
    }
}
