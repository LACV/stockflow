<?php

namespace App\Filament\Resources\MonthlyClosures;

use App\Filament\Resources\MonthlyClosures\Pages\EditMonthlyClosure;
use App\Filament\Resources\MonthlyClosures\Pages\ListMonthlyClosures;
use App\Filament\Resources\MonthlyClosures\Pages\ViewMonthlyClosure;
use App\Filament\Resources\MonthlyClosures\RelationManagers\BalancesRelationManager;
use App\Filament\Resources\MonthlyClosures\RelationManagers\InventorySnapshotsRelationManager;
use App\Filament\Resources\MonthlyClosures\Schemas\MonthlyClosureForm;
use App\Filament\Resources\MonthlyClosures\Tables\MonthlyClosuresTable;
use App\Models\MonthlyClosure;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MonthlyClosureResource extends Resource
{
    protected static ?string $model = MonthlyClosure::class;

    protected static ?string $modelLabel = 'Cierre Mensual';

    protected static ?string $pluralModelLabel = 'Cierres Mensuales';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function form(Schema $schema): Schema
    {
        return MonthlyClosureForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MonthlyClosuresTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            InventorySnapshotsRelationManager::class,
            BalancesRelationManager::class,

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMonthlyClosures::route('/'),
            'view' => ViewMonthlyClosure::route('/{record}'),
            'edit' => EditMonthlyClosure::route('/{record}/edit'),
        ];
    }
}
