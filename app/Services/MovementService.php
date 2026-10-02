<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticlePrice;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class MovementService
{
    public function applyMovement(
        string $type,
        float $currentStock,
        float $currentAvgCost,
        float $quantity,
        float $unitCost
    ): array {
        $newStock = $currentStock;
        $newAvgCost = $currentAvgCost;
        $detailUpdates = [];

        $typeUpper = strtoupper($type);

        if ($typeUpper === 'ENTRY') {
            $newStock = $currentStock + $quantity;
            $newAvgCost = $newStock > 0
                ? (($currentStock * $currentAvgCost) + ($quantity * $unitCost)) / $newStock
                : $unitCost;
        } elseif ($typeUpper === 'EXIT') {
            $newStock = $currentStock - $quantity;
            $detailUpdates['unit_cost'] = $currentAvgCost;
            $detailUpdates['total_cost'] = $quantity * $currentAvgCost;
        } elseif ($typeUpper === 'ADJUSTMENT') {
            $newStock = $currentStock + $quantity;
            if ($quantity > 0) {
                $newAvgCost = $newStock > 0
                    ? (($currentStock * $currentAvgCost) + ($quantity * $unitCost)) / $newStock
                    : $unitCost;
            } else {
                $detailUpdates['unit_cost'] = $currentAvgCost;
                $detailUpdates['total_cost'] = abs($quantity) * $currentAvgCost;
            }
        }

        $newStock = max(0, $newStock);
        $newAvgCost = abs($newAvgCost);

        return [$newStock, $newAvgCost, $detailUpdates];
    }

    public function revertMovement(string $type, float $currentStock, float $quantity): float
    {
        $reverted = match (strtoupper($type)) {
            'ENTRY' => $currentStock - $quantity,
            'EXIT' => $currentStock + $quantity,
            'ADJUSTMENT' => $currentStock - $quantity,
            default => $currentStock,
        };

        return max(0, $reverted);
    }

    public function revertTransactionStock(InventoryTransaction $transaction): void
    {
        $details = $transaction->details()
            ->with('article')
            ->orderByDesc('id')
            ->get();

        foreach ($details as $detail) {
            $article = $detail->article;
            if (! $article) {
                continue;
            }

            $currentStock = (float) $article->stock_quantity;
            $currentAvgCost = (float) $article->current_average_cost;
            $quantity = (float) $detail->quantity;
            $unitCost = (float) $detail->unit_cost;
            $typeUpper = strtoupper($transaction->type);

            $newStock = $currentStock;
            $newAvgCost = $currentAvgCost;

            if ($typeUpper === 'ENTRY') {
                $newStock = $currentStock - $quantity;
                if ($newStock > 0) {
                    $totalValueAfter = ($currentStock * $currentAvgCost) - ($quantity * $unitCost);
                    $newAvgCost = $totalValueAfter / $newStock;
                } else {
                    $newAvgCost = 0;
                }
            } elseif ($typeUpper === 'EXIT') {
                $newStock = $currentStock + $quantity;
            } elseif ($typeUpper === 'ADJUSTMENT') {
                if ($quantity > 0) {
                    $newStock = $currentStock - $quantity;
                    if ($newStock > 0) {
                        $totalValueAfter = ($currentStock * $currentAvgCost) - ($quantity * $unitCost);
                        $newAvgCost = $totalValueAfter / $newStock;
                    } else {
                        $newAvgCost = 0;
                    }
                } else {
                    $newStock = $currentStock - $quantity;
                }
            }

            $newStock = max(0, $newStock);
            $newAvgCost = abs($newAvgCost);

            $article->stock_quantity = $newStock;
            $article->current_average_cost = $newAvgCost;
            $article->saveQuietly();

            ArticlePrice::where('article_id', $detail->article_id)
                ->where('transaction_id', $transaction->id)
                ->delete();
        }
    }

    public function activateTransaction(int $transactionId): void
    {
        $current = DB::table('inventory_transactions')
            ->where('id', $transactionId)
            ->value('status');

        if ($current === 'annulled') {
            return;
        }

        if ($current !== 'active') {
            DB::table('inventory_transactions')
                ->where('id', $transactionId)
                ->update(['status' => 'active']);
        }
    }

    public function syncTransactionStatus(?InventoryTransactionDetail $detail): void
    {
        if (! $detail) {
            return;
        }

        $transaction = $detail->transaction;
        if (! $transaction || $transaction->status === 'annulled') {
            return;
        }

        // Un borrador solo cambia de estado al finalizar (finalize()). Nunca debe
        // activarse al borrar un detalle, o la factura quedaría bloqueada sin guardar.
        if ($transaction->status === 'inactive') {
            return;
        }

        $hasDetails = $transaction->details()
            ->where('id', '!=', $detail->id)
            ->exists();

        $transaction->status = $hasDetails ? 'active' : 'inactive';
        $transaction->saveQuietly();
    }

    public function validarStockSuficiente($details, string $type): array
    {
        $type = strtoupper($type);

        if (! in_array($type, ['EXIT', 'ADJUSTMENT'], true)) {
            return [];
        }

        $necesidades = [];

        foreach ($details as $detail) {
            $articleId = (int) $detail->article_id;
            if (! isset($necesidades[$articleId])) {
                $necesidades[$articleId] = ['demanda' => 0.0, 'article' => $detail->article];
            }

            $qty = (float) $detail->quantity;
            if ($type === 'EXIT') {
                $necesidades[$articleId]['demanda'] += $qty;
            } elseif ($qty < 0) {
                $necesidades[$articleId]['demanda'] += abs($qty);
            }
        }

        $insuficientes = [];

        foreach ($necesidades as $articleId => $data) {
            $article = $data['article'] ?? Article::find($articleId);
            if (! $article) {
                continue;
            }

            $stock = (float) $article->stock_quantity;
            if ($data['demanda'] > $stock) {
                $insuficientes[] = "[{$article->code}] {$article->name}: disponible {$stock} und, se requieren {$data['demanda']} und.";
            }
        }

        return $insuficientes;
    }

    public function notifyIfStockCritical(Article $article, float $newStock): void
    {
        if ($newStock > 0) {
            return;
        }

        $stockLabel = $newStock < 0 ? 'Stock negativo' : 'Stock en cero';
        $color = $newStock < 0 ? 'danger' : 'warning';
        $icon = $newStock < 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-exclamation-circle';

        $body = $newStock < 0
            ? "El artículo [{$article->code}] {$article->name} tiene stock negativo ({$newStock} und). Revisar movimientos."
            : "El artículo [{$article->code}] {$article->name} llegó a stock cero.";

        $notification = Notification::make()
            ->title($stockLabel)
            ->body($body)
            ->icon($icon)
            ->color($color)
            ->actions([
                Action::make('ver_articulo')
                    ->label('Ver artículo')
                    ->url(route('filament.admin.resources.articles.edit', ['record' => $article->id]))
                    ->markAsRead(),
            ]);

        $users = User::permission(['Update:Article', 'ViewAny:Article'])
            ->orWhereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->get();

        foreach ($users as $user) {
            $notification->sendToDatabase($user);
        }
    }

    public function createPriceRecord(
        Article $article,
        InventoryTransactionDetail $detail,
        string $transactionType,
        string $transactionDate,
    ): void {
        ArticlePrice::create([
            'article_id' => $article->id,
            'quantity' => abs((float) $detail->quantity),
            'price' => (float) $detail->unit_cost,
            'date' => $transactionDate,
            'source' => strtoupper($transactionType),
            'transaction_id' => $detail->transaction->id,
        ]);
    }

    public function updatePriceRecord(
        Article $article,
        InventoryTransactionDetail $detail,
        string $transactionType,
        string $transactionDate,
    ): void {
        $priceRecord = ArticlePrice::where('article_id', $article->id)
            ->where('transaction_id', $detail->transaction->id)
            ->first();

        if ($priceRecord) {
            $priceRecord->quantity = abs((float) $detail->quantity);
            $priceRecord->price = (float) $detail->unit_cost;
            $priceRecord->saveQuietly();
        } else {
            $this->createPriceRecord($article, $detail, $transactionType, $transactionDate);
        }
    }
}
