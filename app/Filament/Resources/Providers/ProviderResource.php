<?php

namespace App\Filament\Resources\Providers;

use App\Filament\Resources\Providers\Pages\ManageProviders;
use App\Models\Provider;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProviderResource extends Resource
{
    protected static ?string $model = Provider::class;

    protected static ?string $modelLabel = 'Proveedor';

    protected static ?string $pluralModelLabel = 'Proveedores';

    protected static string|\UnitEnum|null $navigationGroup = 'Catálogos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('provider')
                    ->label('Nombre / Razón social')
                    ->required()
                    ->maxLength(150),
                TextInput::make('document_type')
                    ->label('Tipo de documento')
                    ->maxLength(45)
                    ->placeholder('NIT / CC'),
                TextInput::make('identification')
                    ->label('NIT / Cédula')
                    ->maxLength(20),
                TextInput::make('phone')
                    ->label('Teléfono')
                    ->tel()
                    ->maxLength(15),
                TextInput::make('email')
                    ->label('Correo electrónico')
                    ->email()
                    ->maxLength(80),
                TextInput::make('address')
                    ->label('Dirección')
                    ->maxLength(80),
                Toggle::make('status')
                    ->label('Activo')
                    ->default(true)
                    ->required()
                    ->hiddenOn('create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->label('Nombre / Razón social')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('identification')
                    ->label('NIT / Cédula')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Teléfono'),
                TextColumn::make('email')
                    ->label('Correo electrónico'),
                TextColumn::make('document_type')
                    ->label('Tipo de documento')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('address')
                    ->label('Dirección')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('status')
                    ->label('Activo')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('status')
                    ->label('Activo'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProviders::route('/'),
        ];
    }
}
