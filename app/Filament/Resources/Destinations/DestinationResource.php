<?php

namespace App\Filament\Resources\Destinations;

use App\Filament\Resources\Destinations\Pages\ManageDestinations;
use App\Models\Destination;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DestinationResource extends Resource
{
    protected static ?string $model = Destination::class;

    protected static ?string $modelLabel = 'Ubicación / Equipo';

    protected static ?string $pluralModelLabel = 'Ubicaciones / Equipos';

    protected static string|\UnitEnum|null $navigationGroup = 'Catálogos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ComputerDesktop;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Código')
                    ->default(null),
                TextInput::make('name')
                    ->label('Nombre o Descripción')
                    ->required(),
                Select::make('entity_type')
                    ->label('Tipo de Destino')
                    ->options([
                        'area' => 'Área',
                        'equipment' => 'Equipo',
                    ])
                    ->live()
                    ->required()
                    ->default(null),

                Fieldset::make('Información Técnica del Equipo')
                    ->hidden(fn ($get) => $get('entity_type') !== 'equipment')
                    ->schema([
                        TextInput::make('manufacturer_model')
                            ->label('Marca y Modelo')
                            ->default(null),
                        DatePicker::make('installation_date')
                            ->label('Fecha de Instalación'),
                        TextInput::make('capacity')
                            ->label('Capacidad'),
                        TextInput::make('power')
                            ->label('Voltaje / Poder'),
                        TextInput::make('physical_dimensions')
                            ->label('Dimensiones Físicas'),
                        TextInput::make('frequency_use')
                            ->label('Frecuencia de Uso'),
                        TextInput::make('image')
                            ->label('URL Imagen'),
                        Textarea::make('detailed')
                            ->label('Descripción Detallada')
                            ->columnSpanFull(),
                        Textarea::make('fundamental_pieces')
                            ->label('Repuestos Fundamentales')
                            ->columnSpanFull(),
                        Textarea::make('supplies')
                            ->label('Suministros Compatibles')
                            ->columnSpanFull(),
                        Textarea::make('repair_details')
                            ->label('Detalles de Reparación')
                            ->columnSpanFull(),
                    ])->columns(2)->columnSpanFull(),
                Select::make('state')
                    ->label('Estado Operativo')
                    ->options([
                        'operating' => 'En Operación / Activo',
                        'under installation' => 'En Instalación',
                        'maintenance' => 'En Mantenimiento',
                        'damaged' => 'Dañado / Averiado',
                        'retired' => 'Retirado / De Baja',
                    ])
                    ->required()
                    ->default('operating')
                    ->hiddenOn('create'),
                TextInput::make('responsible_position')
                    ->label('Cargo Responsable')
                    ->default(null),
                Textarea::make('observations')
                    ->label('Observaciones Adicionales')
                    ->default(null)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                TextColumn::make('entity_type')
                    ->label('Tipo')
                    ->searchable(),
                TextColumn::make('manufacturer_model')
                    ->label('Marca / Modelo')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('state')
                    ->label('Estado')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'operating' => 'En Operación',
                        'under installation' => 'En Instalación',
                        'maintenance' => 'En Mantenimiento',
                        'damaged' => 'Dañado / Averiado',
                        'retired' => 'Retirado / De Baja',
                        default => $state ?: 'Sin asignar',
                    })
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'operating' => 'success',
                        'under installation' => 'info',
                        'maintenance' => 'warning',
                        'damaged', 'retired' => 'danger',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('responsible_position')
                    ->label('Responsable')
                    ->searchable(),
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
                //
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
            'index' => ManageDestinations::route('/'),
        ];
    }
}
