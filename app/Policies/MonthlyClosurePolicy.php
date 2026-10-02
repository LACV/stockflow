<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MonthlyClosure;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MonthlyClosurePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MonthlyClosure');
    }

    public function view(AuthUser $authUser, MonthlyClosure $monthlyClosure): bool
    {
        return $authUser->can('View:MonthlyClosure');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MonthlyClosure');
    }

    public function update(AuthUser $authUser, MonthlyClosure $monthlyClosure): bool
    {
        return $authUser->can('Update:MonthlyClosure');
    }

    public function delete(AuthUser $authUser, MonthlyClosure $monthlyClosure): bool
    {
        return $authUser->can('Delete:MonthlyClosure');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:MonthlyClosure');
    }

    public function restore(AuthUser $authUser, MonthlyClosure $monthlyClosure): bool
    {
        return $authUser->can('Restore:MonthlyClosure');
    }

    public function forceDelete(AuthUser $authUser, MonthlyClosure $monthlyClosure): bool
    {
        return $authUser->can('ForceDelete:MonthlyClosure');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MonthlyClosure');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MonthlyClosure');
    }

    public function replicate(AuthUser $authUser, MonthlyClosure $monthlyClosure): bool
    {
        return $authUser->can('Replicate:MonthlyClosure');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MonthlyClosure');
    }

    public function reopen(AuthUser $authUser, MonthlyClosure $monthlyClosure): bool
    {
        return $authUser->can('Reopen:MonthlyClosure');
    }
}
