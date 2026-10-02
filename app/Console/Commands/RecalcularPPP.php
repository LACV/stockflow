<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\InventoryTransactionDetail;
use Illuminate\Console\Command;

class RecalcularPPP extends Command
{
    protected $signature = 'inventario:recalcular-ppp';

    protected $description = 'Recalcula el stock y PPP de todos los artículos desde las transacciones activas';

    public function handle(): void
    {
        $articles = Article::all();
        $bar = $this->output->createProgressBar($articles->count());
        $bar->start();

        foreach ($articles as $article) {
            $stock = 0;
            $avgCost = 0;

            $details = InventoryTransactionDetail::query()
                ->join('inventory_transactions as t', 't.id', '=', 'inventory_transaction_details.inventory_transaction_id')
                ->where('inventory_transaction_details.article_id', $article->id)
                ->where('t.status', 'active')
                ->orderBy('t.transaction_date', 'asc')
                ->orderBy('inventory_transaction_details.created_at', 'asc')
                ->orderBy('inventory_transaction_details.id', 'asc')
                ->select('inventory_transaction_details.quantity', 'inventory_transaction_details.unit_cost', 't.type')
                ->get();

            foreach ($details as $detail) {
                $type = strtoupper($detail->type);
                $qty = (float) $detail->quantity;
                $cost = (float) $detail->unit_cost;

                if ($type === 'ENTRY') {
                    $newStock = $stock + $qty;
                    $avgCost = $newStock > 0
                        ? (($stock * $avgCost) + ($qty * $cost)) / $newStock
                        : $cost;
                    $stock = $newStock;

                } elseif ($type === 'EXIT') {
                    $stock = max(0, $stock - $qty);
                    if ($stock <= 0) {
                        $avgCost = 0;
                    }

                } elseif ($type === 'ADJUSTMENT') {
                    if ($qty > 0) {
                        $newStock = $stock + $qty;
                        $avgCost = $newStock > 0
                            ? (($stock * $avgCost) + ($qty * $cost)) / $newStock
                            : $cost;
                        $stock = $newStock;
                    } else {
                        $stock = max(0, $stock + $qty);
                        if ($stock <= 0) {
                            $avgCost = 0;
                        }
                    }
                }
            }

            $stock = max(0, $stock);
            $avgCost = abs($avgCost);

            $article->stock_quantity = $stock;
            $article->current_average_cost = $avgCost;
            $article->saveQuietly();

            $this->line(" ✓ <info>{$article->name}</info>: stock=<comment>{$stock}</comment>, PPP=<comment>{$avgCost}</comment>");
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('¡PPP recalculado exitosamente para todos los artículos!');
    }
}
