<?php

namespace App\Filament\Resources\EntryOrders;

use App\Filament\Resources\EntryOrders\Pages\CreateEntryOrder;
use App\Filament\Resources\EntryOrders\Pages\EditEntryOrder;
use App\Filament\Resources\EntryOrders\Pages\ListEntryOrders;
use App\Filament\Resources\EntryOrders\Pages\ViewEntryOrder;
use App\Filament\Resources\EntryOrders\RelationManagers\DetailsRelationManager;
use App\Filament\Resources\EntryOrders\Schemas\EntryOrderForm;
use App\Filament\Resources\EntryOrders\Schemas\EntryOrderInfolist;
use App\Filament\Resources\EntryOrders\Tables\EntryOrdersTable;
use App\Models\EntryOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EntryOrderResource extends Resource
{
    protected static ?string $model = EntryOrder::class;

    protected static ?string $modelLabel = 'Entrada (Factura)';

    protected static ?string $pluralModelLabel = 'Entradas de Inventario';

    protected static \UnitEnum|string|null $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::InboxArrowDown;

    public static function form(Schema $schema): Schema
    {
        return EntryOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EntryOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EntryOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DetailsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEntryOrders::route('/'),
            'create' => CreateEntryOrder::route('/create'),
            'view' => ViewEntryOrder::route('/{record}'),
            'edit' => EditEntryOrder::route('/{record}/edit'),
        ];
    }
}
