<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Destination;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DestinationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Destination');
    }

    public function view(AuthUser $authUser, Destination $destination): bool
    {
        return $authUser->can('View:Destination');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Destination');
    }

    public function update(AuthUser $authUser, Destination $destination): bool
    {
        return $authUser->can('Update:Destination');
    }

    public function delete(AuthUser $authUser, Destination $destination): bool
    {
        return $authUser->can('Delete:Destination');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Destination');
    }

    public function restore(AuthUser $authUser, Destination $destination): bool
    {
        return $authUser->can('Restore:Destination');
    }

    public function forceDelete(AuthUser $authUser, Destination $destination): bool
    {
        return $authUser->can('ForceDelete:Destination');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Destination');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Destination');
    }

    public function replicate(AuthUser $authUser, Destination $destination): bool
    {
        return $authUser->can('Replicate:Destination');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Destination');
    }

    public function reopen(AuthUser $authUser, Destination $destination): bool
    {
        return $authUser->can('Reopen:Destination');
    }
}
