<?php

namespace App\Filament\Resources\Articles;

use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Articles\RelationManagers\PricesRelationManager;
use App\Filament\Resources\Articles\Schemas\ArticleForm;
use App\Filament\Resources\Articles\Tables\ArticlesTable;
use App\Models\Article;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $modelLabel = 'Artículo / Repuesto';

    protected static ?string $pluralModelLabel = 'Artículos / Repuestos';

    protected static string|\UnitEnum|null $navigationGroup = 'Catálogos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name', 'reference', 'brand.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return "{$record->code} · {$record->name}";
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Referencia' => $record->reference ?: '—',
            'Stock' => rtrim(rtrim(number_format((float) $record->stock_quantity, 4, '.', ''), '0'), '.').' '.($record->unit_of_measure ?? 'und'),
            'PPP' => '$ '.number_format((float) $record->current_average_cost, 2, '.', ','),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['brand']);
    }

    public static function form(Schema $schema): Schema
    {
        return ArticleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ArticlesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PricesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }
}
