<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticlePrice;
use App\Models\Brand;
use App\Models\Department;
use App\Models\Destination;
use App\Models\Employee;
use App\Models\MonthlyClosure;
use App\Models\Provider;
use App\Models\Type;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyData extends Command
{
    protected $signature = 'app:migrate-legacy-data';

    protected $description = 'Migra los datos (catálogos y configuraciones) desde la BD legacy hacia la BD final.';

    public function handle()
    {
        // Setup legacy connection dynamically to avoid messy config modifications
        config(['database.connections.legacy' => array_merge(config('database.connections.mysql'), [
            'database' => 'almacen_legacy',
        ])]);

        $this->info('Conectando a base de datos legacy...');

        $this->info('Migrando Marcas (brands)...');
        $brands = DB::connection('legacy')->table('brands')->get();
        foreach ($brands as $brand) {
            Brand::updateOrCreate(
                ['id' => $brand->id],
                [
                    'name' => $brand->brand,
                    'is_active' => $brand->status,
                    'created_at' => $brand->created_at,
                    'updated_at' => $brand->updated_at,
                ]
            );
        }
        $this->info(" -> {$brands->count()} marcas migradas.");

        $this->info('Migrando Departamentos (departments)...');
        $departments = DB::connection('legacy')->table('departments')->get();
        foreach ($departments as $dept) {
            Department::updateOrCreate(
                ['id' => $dept->id],
                [
                    'name' => $dept->name,
                    'is_active' => $dept->status,
                    'created_at' => $dept->created_at,
                    'updated_at' => $dept->updated_at,
                ]
            );
        }
        $this->info(" -> {$departments->count()} departamentos migrados.");

        $this->info('Migrando Tipos (types)...');
        $types = DB::connection('legacy')->table('types')->get();
        foreach ($types as $type) {
            Type::updateOrCreate(
                ['id' => $type->id],
                [
                    'name' => $type->type,
                    'is_active' => $type->status,
                    'created_at' => $type->created_at,
                    'updated_at' => $type->updated_at,
                ]
            );
        }
        $this->info(" -> {$types->count()} tipos migrados.");

        $this->info('Migrando Proveedores (providers)...');
        $providers = DB::connection('legacy')->table('providers')->get();
        foreach ($providers as $provider) {
            Provider::updateOrCreate(
                ['id' => $provider->id],
                [
                    'provider' => $provider->provider,
                    'address' => $provider->address,
                    'email' => $provider->email,
                    'phone' => $provider->phone,
                    'status' => $provider->status,
                    'document_type' => $provider->document_type,
                    'identification' => $provider->identification,
                    'created_at' => $provider->created_at,
                    'updated_at' => $provider->updated_at,
                ]
            );
        }
        $this->info(" -> {$providers->count()} proveedores migrados.");

        $this->info('Migrando Empleados (employees)...');
        $employees = DB::connection('legacy')->table('employees')->get();
        foreach ($employees as $emp) {
            Employee::updateOrCreate(
                ['id' => $emp->id],
                [
                    'name' => $emp->name,
                    'email' => $emp->email,
                    'phone' => $emp->phone,
                    'status' => $emp->status,
                    'department_id' => $emp->department_id,
                    'identification_e' => $emp->identification_e,
                    'created_at' => $emp->created_at,
                    'updated_at' => $emp->updated_at,
                ]
            );
        }
        $this->info(" -> {$employees->count()} empleados migrados.");

        $this->info('Migrando Artículos/Repuestos (products)...');
        $products = DB::connection('legacy')->table('products')->get();
        foreach ($products as $prod) {
            Article::updateOrCreate(
                ['id' => $prod->id],
                [
                    'code' => $prod->prodcod,
                    'name' => $prod->product,
                    'brand_id' => $prod->brand_id,
                    'type_id' => $prod->type_id,
                    'stock_quantity' => floatval($prod->quantity ?? 0),
                    'is_active' => $prod->status,
                    'reference' => $prod->reference,
                    'unit_of_measure' => match (strtoupper(trim($prod->mesureunit))) {
                        'UND', 'UNDAD', 'UNDIAD', 'UNDIDAD', 'UN', 'UNI', 'UNIDADES' => 'UNIDAD',
                        'KG', 'KILOS', 'KILOGRAMO' => 'KILO',
                        'LT', 'LTS', 'LITROS', '4 LT' => 'LITRO',
                        'GALONES', 'GALS' => 'GALON',
                        'MTS', 'METROS' => 'METRO',
                        'LB', 'LBS', 'LIBRAS' => 'LIBRA',
                        'CUÑETES', 'CUÃ‘ETE' => 'CUÑETE',
                        'PAQ', 'PAQUETES' => 'PAQUETE',
                        'BOTELLAS' => 'BOTELLA',
                        'CAJAS' => 'CAJA',
                        'ROLLOS' => 'ROLLO',
                        'JUEGOS', 'JUEGO X 2 UNDS' => 'JUEGO',
                        'FRASCOS' => 'FRASCO',
                        'TUBOS' => 'TUBO',
                        'GARRAFAS' => 'GARRAFA',
                        default => strtoupper(trim($prod->mesureunit))
                    },
                    'barcode' => $prod->barcod,
                    'created_at' => $prod->created_at,
                    'updated_at' => $prod->updated_at,
                ]
            );
        }
        $this->info(" -> {$products->count()} artículos migrados.");

        $this->info('Migrando Histórico de Precios (prices)...');
        $prices = DB::connection('legacy')->table('prices')->get();
        foreach ($prices as $price) {
            ArticlePrice::updateOrCreate(
                ['id' => $price->id],
                [
                    'article_id' => $price->id_prod,
                    'quantity' => floatval($price->quantity),
                    'price' => floatval($price->price),
                    'date' => ($price->date === '0000-00-00' || empty($price->date)) ? null : $price->date,
                    'is_active' => $price->status,
                    'created_at' => $price->created_at,
                    'updated_at' => $price->updated_at,
                ]
            );
        }
        $this->info(" -> {$prices->count()} registros de precios históricos migrados.");

        $this->info('Migrando Destinos y Equipos (destinations)...');
        $destinations = DB::connection('legacy')->table('destinations')->get();
        foreach ($destinations as $dest) {
            Destination::updateOrCreate(
                ['id' => $dest->id],
                [
                    'code' => $dest->code,
                    'name' => $dest->name,
                    'entity_type' => $dest->entity_type,
                    'manufacturer_model' => $dest->manufacturer_model,
                    'state' => $dest->state,
                    'responsible_position' => $dest->responsible_position,
                    'observations' => $dest->observations,
                    'installation_date' => ($dest->installation_date === '0000-00-00') ? null : $dest->installation_date,
                    'detailed' => $dest->detailed,
                    'capacity' => $dest->capacity,
                    'power' => $dest->power,
                    'physical_dimensions' => $dest->physical_dimensions,
                    'fundamental_pieces' => $dest->fundamental_pieces,
                    'supplies' => $dest->supplies,
                    'repair_details' => $dest->repair_details,
                    'frequency_use' => $dest->frequency_use,
                    'image' => $dest->image,
                    'created_at' => $dest->created_at,
                    'updated_at' => $dest->updated_at,
                ]
            );
        }
        $this->info(" -> {$destinations->count()} destinos migrados.");

        $this->info('Migrando Configuraciones (configs)...');
        $config = DB::connection('legacy')->table('configs')->orderBy('id', 'desc')->first();
        if ($config) {
            $this->info("   Empresa encontrada en legacy: {$config->company} (NIT: {$config->identification})");

            // Creamos un cierre mensual del sistema legacy si no existe, como punto de partida
            $closure = MonthlyClosure::firstOrCreate(
                ['month' => $config->month, 'year' => $config->year],
                ['is_closed' => false]
            );
            $this->info("   Inicializado periodo actual de inventario en bases al config: Mes {$config->month}, Año {$config->year}.");
        }

        $this->info('¡Migración Legacy completada exitosamente!');
    }
}
