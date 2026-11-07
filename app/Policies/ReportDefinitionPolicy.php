<?php

namespace App\Policies;

use App\Models\ReportDefinition;
use App\Models\User;

/** Anyone may view a report shared with them; only its owner may change it. */
class ReportDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ReportDefinition $report): bool
    {
        return $report->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ReportDefinition $report): bool
    {
        return $report->isOwnedBy($user);
    }

    public function delete(User $user, ReportDefinition $report): bool
    {
        return $report->isOwnedBy($user);
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }

    public function restore(User $user, ReportDefinition $report): bool
    {
        return $report->isOwnedBy($user);
    }

    public function restoreAny(User $user): bool
    {
        return true;
    }

    public function forceDelete(User $user, ReportDefinition $report): bool
    {
        return $report->isOwnedBy($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return true;
    }
}
