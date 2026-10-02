<?php

namespace App\Filament\Resources\AdjustmentOrders;

use App\Filament\Resources\AdjustmentOrders\Pages\CreateAdjustmentOrder;
use App\Filament\Resources\AdjustmentOrders\Pages\EditAdjustmentOrder;
use App\Filament\Resources\AdjustmentOrders\Pages\ListAdjustmentOrders;
use App\Filament\Resources\AdjustmentOrders\Pages\ViewAdjustmentOrder;
use App\Filament\Resources\AdjustmentOrders\RelationManagers\DetailsRelationManager;
use App\Filament\Resources\AdjustmentOrders\Schemas\AdjustmentOrderForm;
use App\Filament\Resources\AdjustmentOrders\Schemas\AdjustmentOrderInfolist;
use App\Filament\Resources\AdjustmentOrders\Tables\AdjustmentOrdersTable;
use App\Models\AdjustmentOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AdjustmentOrderResource extends Resource
{
    protected static ?string $model = AdjustmentOrder::class;

    protected static ?string $modelLabel = 'Ajuste de Stock';

    protected static ?string $pluralModelLabel = 'Ajustes de Inventario';

    protected static \UnitEnum|string|null $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::AdjustmentsHorizontal;

    public static function form(Schema $schema): Schema
    {
        return AdjustmentOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdjustmentOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdjustmentOrdersTable::configure($table);
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
            'index' => ListAdjustmentOrders::route('/'),
            'create' => CreateAdjustmentOrder::route('/create'),
            'view' => ViewAdjustmentOrder::route('/{record}'),
            'edit' => EditAdjustmentOrder::route('/{record}/edit'),
        ];
    }
}
