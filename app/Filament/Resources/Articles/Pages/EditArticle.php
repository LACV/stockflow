<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Concerns\PreventsUnsavedChanges;
use App\Filament\Resources\Articles\ArticleResource;
use App\Models\InventoryTransactionDetail;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditArticle extends EditRecord
{
    use PreventsUnsavedChanges;

    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (DeleteAction $action) {
                    $record = $action->getRecord();
                    $hasDetails = InventoryTransactionDetail::where('article_id', $record->id)->exists();

                    if ($hasDetails) {
                        Notification::make()
                            ->title('No se puede eliminar')
                            ->body("El artículo [{$record->code}] {$record->name} tiene movimientos en el inventario. No se puede eliminar.")
                            ->warning()
                            ->persistent()
                            ->send();
                        $action->halt();
                    }
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
