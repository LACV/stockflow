<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyTransactions extends Command
{
    protected $signature = 'app:migrate-legacy-transactions';

    protected $description = 'Migra ordenes (orders + order_details) desde BD legacy';

    public function handle()
    {
        // 1. Configurar conexión legacy (igual que MigrateLegacyData)
        config(['database.connections.legacy' => array_merge(
            config('database.connections.mysql'),
            ['database' => 'almacen_legacy']
        )]);

        // 2. Migrar orders → inventory_transactions
        $orders = DB::connection('legacy')->table('orders')->get();
        $bar = $this->output->createProgressBar($orders->count());
        $bar->start();

        foreach ($orders as $order) {
            // Mapear order_type
            $type = match ($order->order_type) {
                'E' => 'ENTRY',
                'S' => 'EXIT',
                'AS' => 'ADJUSTMENT',
                default => null,
            };

            if (! $type) {
                $this->warn("Order #{$order->id}: tipo '{$order->order_type}' desconocido, se omite");

                continue;
            }

            DB::table('inventory_transactions')->insert([
                'id' => $order->id,
                'type' => $type,
                'transaction_date' => $order->date ?: null,
                'reference_document' => $order->entry_order ?: null,
                'invoice_number' => $order->invoice ?: null,
                'purchase_order' => $order->purchase_order ?: null,
                'provider_id' => $order->provider_id ?: null,
                'notes' => $order->order_number ?: null,
                'status' => $order->status == 1 ? 'active' : 'inactive',
                'month' => $order->month,
                'year' => $order->year,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
            ]);

            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("{$orders->count()} transacciones migradas.");

        // 3. Migrar order_details → inventory_transaction_details
        $details = DB::connection('legacy')->table('order_details')->get();
        $bar2 = $this->output->createProgressBar($details->count());
        $bar2->start();

        foreach ($details as $detail) {
            $quantity = floatval($detail->quantity);
            $unitCost = floatval($detail->price);
            $totalCost = $quantity * $unitCost;

            DB::table('inventory_transaction_details')->insert([
                'id' => $detail->id,
                'inventory_transaction_id' => $detail->id_order,
                'article_id' => $detail->id_product,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'resulting_average_cost' => 0,
                'employee_id' => $detail->id_employee ?: null,
                'destination_id' => $detail->id_destination ?: null,
                'movement_date' => $detail->date ?: null,
                'movement_time' => $detail->hour ?: null,
                'observation' => $detail->observation ?: null,
                'created_at' => $detail->created_at,
                'updated_at' => $detail->updated_at,
            ]);

            $bar2->advance();
        }
        $bar2->finish();
        $this->newLine();
        $this->info("{$details->count()} detalles migrados.");

        $this->info('¡Migración de transacciones completada!');
        $this->warn('Ejecuta: php artisan inventario:recalcular-ppp');
    }
}
