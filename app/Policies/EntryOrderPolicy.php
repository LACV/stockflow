<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EntryOrder;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class EntryOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EntryOrder');
    }

    public function view(AuthUser $authUser, EntryOrder $entryOrder): bool
    {
        return $authUser->can('View:EntryOrder');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EntryOrder');
    }

    public function update(AuthUser $authUser, EntryOrder $entryOrder): bool
    {
        return $authUser->can('Update:EntryOrder');
    }

    public function delete(AuthUser $authUser, EntryOrder $entryOrder): bool
    {
        return $authUser->can('Delete:EntryOrder');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:EntryOrder');
    }

    public function restore(AuthUser $authUser, EntryOrder $entryOrder): bool
    {
        return $authUser->can('Restore:EntryOrder');
    }

    public function forceDelete(AuthUser $authUser, EntryOrder $entryOrder): bool
    {
        return $authUser->can('ForceDelete:EntryOrder');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:EntryOrder');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:EntryOrder');
    }

    public function replicate(AuthUser $authUser, EntryOrder $entryOrder): bool
    {
        return $authUser->can('Replicate:EntryOrder');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:EntryOrder');
    }

    public function reopen(AuthUser $authUser, EntryOrder $entryOrder): bool
    {
        return $authUser->can('Reopen:EntryOrder');
    }
}
