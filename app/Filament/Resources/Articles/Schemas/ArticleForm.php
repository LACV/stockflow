<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Models\Article;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ArticleForm
{
    public static function getCreateFields(): array
    {
        return [
            Section::make('Identificación')
                ->description('Datos básicos del artículo o repuesto')
                ->icon('heroicon-o-identification')
                ->collapsible()
                ->schema([
                    TextInput::make('code')
                        ->label('Código')
                        ->default(null)
                        ->helperText('Si lo deja vacío, se generará automáticamente un código de 5 dígitos. También puede usar el botón "Generar".')
                        ->suffixAction(
                            Action::make('generateCode')
                                ->label('Generar')
                                ->icon('heroicon-o-sparkles')
                                ->color('primary')
                                ->tooltip('Generar código automático (5 dígitos)')
                                ->action(function (TextInput $component, Set $set) {
                                    $set($component->getStatePath(), Article::generateUniqueCode());
                                })
                        ),
                    TextInput::make('name')
                        ->label('Nombre del Repuesto/Artículo')
                        ->required()
                        ->columnSpanFull(),
                    TextInput::make('reference')
                        ->label('Referencia / Parte')
                        ->default(null),

                    Select::make('unit_of_measure')
                        ->label('Unidad de Medida')
                        ->options([
                            'UNIDAD' => 'Unidad',
                            'KILO' => 'Kilo (Kg)',
                            'LIBRA' => 'Libra (Lb)',
                            'GRAMO' => 'Gramo (g)',
                            'TONELADA' => 'Tonelada (Ton)',
                            'METRO' => 'Metro (m)',
                            'CENTIMETRO' => 'Centímetro (cm)',
                            'MILIMETRO' => 'Milímetro (mm)',
                            'M2' => 'Metro Cuadrado (m2)',
                            'PULGADA' => 'Pulgada (in)',
                            'LITRO' => 'Litro (Lt)',
                            'MILILITRO' => 'Mililitro (ml)',
                            'GALON' => 'Galón (Gal)',
                            'CUÑETE' => 'Cuñete',
                            'CANECA' => 'Caneca / Balde',
                            'BOTELLA' => 'Botella',
                            'GARRAFA' => 'Garrafa',
                            'TUBO' => 'Tubo',
                            'JUEGO' => 'Juego / Set',
                            'KIT' => 'Kit',
                            'PAR' => 'Par',
                            'ROLLO' => 'Rollo',
                            'CAJA' => 'Caja',
                            'BOLSA' => 'Bolsa / Paquete',
                            'BULTO' => 'Bulto',
                            'FRASCO' => 'Frasco / Tarro',
                            'BARRA' => 'Barra',
                            'VARILLA' => 'Varilla',
                            'PIEZA' => 'Pieza',
                            'PLIEGO' => 'Pliego',
                        ])
                        ->searchable()
                        ->default('UNIDAD')
                        ->required(),
                ])->columns(2)->columnSpanFull(),

            Section::make('Clasificación')
                ->description('Categorización del artículo')
                ->icon('heroicon-o-tag')
                ->collapsible()
                ->schema([
                    Select::make('brand_id')
                        ->label('Marca')
                        ->relationship('brand', 'name', modifyQueryUsing: fn ($query) => $query->where('is_active', true))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Nombre de la Marca')
                                ->required(),
                            Toggle::make('is_active')
                                ->label('Activo')
                                ->default(true)
                                ->required()
                                ->hidden(),
                        ]),
                    Select::make('department_id')
                        ->label('Departamento')
                        ->relationship('department', 'name', modifyQueryUsing: fn ($query) => $query->where('is_active', true))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Nombre del Departamento')
                                ->required(),
                            Toggle::make('is_active')
                                ->label('Activo')
                                ->default(true)
                                ->required()
                                ->hidden(),
                        ]),
                    Select::make('type_id')
                        ->label('Tipo de Artículo')
                        ->relationship('type', 'name', modifyQueryUsing: fn ($query) => $query->where('is_active', true))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Nombre del Tipo')
                                ->required(),
                            Toggle::make('is_active')
                                ->label('Activo')
                                ->default(true)
                                ->required()
                                ->hidden(),
                        ]),
                ])->columns(3)->columnSpanFull(),

            Section::make('Inventario')
                ->description('Información de stock y costos')
                ->icon('heroicon-o-archive-box')
                ->collapsible()
                ->collapsed(fn ($livewire) => $livewire instanceof EditRecord)
                ->schema([
                    TextInput::make('stock_quantity')
                        ->label('Cantidad en Stock')
                        ->numeric()
                        ->default(0.0)
                        ->readOnly(),
                    TextInput::make('current_average_cost')
                        ->label('Costo Promedio (Media Ponderada)')
                        ->numeric()
                        ->default(0.0)
                        ->prefix('$')
                        ->readOnly(),
                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true)
                        ->required(),
                ])->columns(3)->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::getCreateFields());
    }
}
