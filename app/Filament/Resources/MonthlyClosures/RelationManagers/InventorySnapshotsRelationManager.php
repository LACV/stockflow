<?php

namespace App\Filament\Resources\MonthlyClosures\RelationManagers;

use App\Models\Article;
use App\Models\MonthlyClosureSnapshot;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InventorySnapshotsRelationManager extends RelationManager
{
    protected static string $relationship = 'snapshots';

    protected static ?string $title = 'Inventario al Cierre';

    protected static ?string $modelLabel = 'Artículo';

    protected static ?string $pluralModelLabel = 'Inventario Completo';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->is_closed;
    }

    protected function canEditSnapshots(): bool
    {
        return $this->ownerRecord->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure');
    }

    public function isReadOnly(): bool
    {
        return ! $this->canEditSnapshots();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('article_id')
                ->label('Artículo')
                ->relationship('article', modifyQueryUsing: fn ($query) => $query->orderBy('name'))
                ->getOptionLabelFromRecordUsing(fn (Article $record) => $record->code.' · '.$record->name.($record->reference ? ' · '.$record->reference : '').($record->brand ? ' · ('.$record->brand->name.')' : '').($record->is_active ? '' : ' · (inactivo)'))
                ->searchable(['name', 'reference', 'code'])
                ->preload()
                ->required()
                ->rules([
                    fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                        $exists = MonthlyClosureSnapshot::where('monthly_closure_id', $this->ownerRecord->id)
                            ->where('article_id', $value)
                            ->exists();

                        if ($exists) {
                            $article = Article::find($value);
                            $fail("El artículo {$article?->name} ya está en el inventario de este cierre.");
                        }
                    },
                ])
                ->disabled(fn (string $operation): bool => $operation === 'edit'),

            TextInput::make('stock_quantity')
                ->label('Cantidad en stock')
                ->numeric()
                ->minValue(0)
                ->required()
                ->suffix('und'),

            TextInput::make('average_cost')
                ->label('Costo promedio')
                ->numeric()
                ->minValue(0)
                ->required()
                ->prefix('$'),
        ]);
    }

    public function table(Table $table): Table
    {
        $editable = $this->canEditSnapshots();

        return $table
            ->columns([
                TextColumn::make('article.code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('article.name')
                    ->label('Artículo')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-cube')
                    ->iconColor('primary')
                    ->weight('medium'),

                TextColumn::make('article.reference')
                    ->label('Referencia')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('article.brand.name')
                    ->label('Marca')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('article.department.name')
                    ->label('Departamento')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('stock_quantity')
                    ->label('Stock al Cierre')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format($state, 4, '.', ''), '0'), '.').' und')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : 'warning')),

                TextColumn::make('average_cost')
                    ->label('Costo Promedio')
                    ->money('COP', true)
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('total_value')
                    ->label('Valor Total')
                    ->money('COP', true)
                    ->sortable()
                    ->alignEnd()
                    ->weight('bold')
                    ->color('success'),
            ])
            ->headerActions($editable ? [
                CreateAction::make()
                    ->label('Agregar artículo al inventario histórico')
                    ->modalHeading('Agregar artículo al inventario histórico')
                    ->modalDescription('Este artículo se agregará únicamente al inventario de este mes cerrado. No afecta el inventario actual.')
                    ->mutateDataUsing(function (array $data): array {
                        $data['total_value'] = ($data['stock_quantity'] ?? 0) * ($data['average_cost'] ?? 0);

                        return $data;
                    })
                    ->after(function () {
                        $this->dispatch('refresh-snapshots');
                    }),
            ] : [])
            ->actions($editable ? [
                EditAction::make()
                    ->modalHeading('Editar artículo del inventario histórico')
                    ->mutateDataUsing(function (array $data): array {
                        $data['total_value'] = ($data['stock_quantity'] ?? 0) * ($data['average_cost'] ?? 0);

                        return $data;
                    })
                    ->after(function () {
                        $this->dispatch('refresh-snapshots');
                    }),

                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading('¿Eliminar artículo del inventario histórico?')
                    ->modalDescription('Este artículo será eliminado únicamente del inventario de este mes cerrado. No afecta el inventario actual.')
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->modalCancelActionLabel('Cancelar')
                    ->after(function () {
                        $this->dispatch('refresh-snapshots');
                    }),
            ] : [])
            ->defaultSort('article.name')
            ->striped()
            ->emptyStateIcon('heroicon-o-cube')
            ->emptyStateHeading('Sin inventario')
            ->emptyStateDescription(fn () => $editable
                ? 'No hay artículos en el inventario histórico. Use el botón "Agregar artículo al inventario histórico" para añadirlos.'
                : 'No hay artículos activos registrados en el momento del cierre.');
    }
}
