<?php

namespace App\Filament\Resources\MonthlyClosures\Pages;

use App\Filament\Resources\MonthlyClosures\MonthlyClosureResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMonthlyClosure extends EditRecord
{
    protected static string $resource = MonthlyClosureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => ! $this->record->is_closed),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
