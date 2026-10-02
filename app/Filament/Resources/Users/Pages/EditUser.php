<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Concerns\PreventsUnsavedChanges;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use PreventsUnsavedChanges;

    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar cambios')
                ->action(fn () => $this->save()),
            Action::make('cancel')
                ->label('Cancelar')
                ->url(fn (): string => $this->getResource()::getUrl('index')),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
