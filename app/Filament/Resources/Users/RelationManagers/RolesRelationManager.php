<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class RolesRelationManager extends RelationManager
{
    protected static string $relationship = 'roles';

    protected static ?string $title = 'Roles';

    protected static ?string $label = 'rol';

    protected static ?string $pluralLabel = 'Roles';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Rol')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'panel_user' => 'gray',
                        default => 'primary',
                    }),
                TextColumn::make('guard_name')
                    ->label('Guard')
                    ->size('sm')
                    ->color('gray'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Asignar rol')
                    ->preloadRecordSelect()
                    ->before(function (AttachAction $action) {
                        $userId = $this->getOwnerRecord()->getKey();
                        $realRoles = DB::table('model_has_roles')
                            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                            ->where('model_has_roles.model_id', $userId)
                            ->where('model_has_roles.model_type', User::class)
                            ->where('roles.name', '!=', 'panel_user')
                            ->count();

                        if ($realRoles > 0) {
                            Notification::make()
                                ->title('Rol no asignado')
                                ->body('Solo se puede asignar un rol por usuario. El usuario ya tiene un rol asignado.')
                                ->warning()
                                ->persistent()
                                ->send();
                            $action->halt();
                        }
                    })
                    ->after(function () {
                        try {
                            $this->getOwnerRecord()->removeRole('panel_user');
                        } catch (RoleDoesNotExist) {
                            //
                        }
                    }),
            ])
            ->actions([
                DetachAction::make()
                    ->label('Remover rol'),
            ])
            ->bulkActions([]);
    }
}
