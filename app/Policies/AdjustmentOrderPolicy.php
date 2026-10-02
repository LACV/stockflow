<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdjustmentOrder;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AdjustmentOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AdjustmentOrder');
    }

    public function view(AuthUser $authUser, AdjustmentOrder $adjustmentOrder): bool
    {
        return $authUser->can('View:AdjustmentOrder');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AdjustmentOrder');
    }

    public function update(AuthUser $authUser, AdjustmentOrder $adjustmentOrder): bool
    {
        return $authUser->can('Update:AdjustmentOrder');
    }

    public function delete(AuthUser $authUser, AdjustmentOrder $adjustmentOrder): bool
    {
        return $authUser->can('Delete:AdjustmentOrder');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:AdjustmentOrder');
    }

    public function restore(AuthUser $authUser, AdjustmentOrder $adjustmentOrder): bool
    {
        return $authUser->can('Restore:AdjustmentOrder');
    }

    public function forceDelete(AuthUser $authUser, AdjustmentOrder $adjustmentOrder): bool
    {
        return $authUser->can('ForceDelete:AdjustmentOrder');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AdjustmentOrder');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AdjustmentOrder');
    }

    public function replicate(AuthUser $authUser, AdjustmentOrder $adjustmentOrder): bool
    {
        return $authUser->can('Replicate:AdjustmentOrder');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AdjustmentOrder');
    }

    public function reopen(AuthUser $authUser, AdjustmentOrder $adjustmentOrder): bool
    {
        return $authUser->can('Reopen:AdjustmentOrder');
    }
}
