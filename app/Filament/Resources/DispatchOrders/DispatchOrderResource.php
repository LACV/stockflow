<?php

namespace App\Filament\Resources\DispatchOrders;

use App\Filament\Resources\DispatchOrders\Pages\CreateDispatchOrder;
use App\Filament\Resources\DispatchOrders\Pages\EditDispatchOrder;
use App\Filament\Resources\DispatchOrders\Pages\ListDispatchOrders;
use App\Filament\Resources\DispatchOrders\Pages\ViewDispatchOrder;
use App\Filament\Resources\DispatchOrders\RelationManagers\DetailsRelationManager;
use App\Filament\Resources\DispatchOrders\Schemas\DispatchOrderForm;
use App\Filament\Resources\DispatchOrders\Schemas\DispatchOrderInfolist;
use App\Filament\Resources\DispatchOrders\Tables\DispatchOrdersTable;
use App\Models\DispatchOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DispatchOrderResource extends Resource
{
    protected static ?string $model = DispatchOrder::class;

    protected static ?string $modelLabel = 'Salida (Mensual)';

    protected static ?string $pluralModelLabel = 'Salidas de Inventario';

    protected static \UnitEnum|string|null $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    public static function form(Schema $schema): Schema
    {
        return DispatchOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DispatchOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DispatchOrdersTable::configure($table);
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
            'index' => ListDispatchOrders::route('/'),
            'create' => CreateDispatchOrder::route('/create'),
            'view' => ViewDispatchOrder::route('/{record}'),
            'edit' => EditDispatchOrder::route('/{record}/edit'),
        ];
    }
}
