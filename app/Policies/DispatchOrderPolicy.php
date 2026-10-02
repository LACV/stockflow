<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DispatchOrder;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DispatchOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DispatchOrder');
    }

    public function view(AuthUser $authUser, DispatchOrder $dispatchOrder): bool
    {
        return $authUser->can('View:DispatchOrder');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DispatchOrder');
    }

    public function update(AuthUser $authUser, DispatchOrder $dispatchOrder): bool
    {
        return $authUser->can('Update:DispatchOrder');
    }

    public function delete(AuthUser $authUser, DispatchOrder $dispatchOrder): bool
    {
        return $authUser->can('Delete:DispatchOrder');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DispatchOrder');
    }

    public function restore(AuthUser $authUser, DispatchOrder $dispatchOrder): bool
    {
        return $authUser->can('Restore:DispatchOrder');
    }

    public function forceDelete(AuthUser $authUser, DispatchOrder $dispatchOrder): bool
    {
        return $authUser->can('ForceDelete:DispatchOrder');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DispatchOrder');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DispatchOrder');
    }

    public function replicate(AuthUser $authUser, DispatchOrder $dispatchOrder): bool
    {
        return $authUser->can('Replicate:DispatchOrder');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DispatchOrder');
    }

    public function reopen(AuthUser $authUser, DispatchOrder $dispatchOrder): bool
    {
        return $authUser->can('Reopen:DispatchOrder');
    }
}
