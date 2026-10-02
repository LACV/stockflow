<?php

namespace App\Observers;

use App\Models\ArticlePrice;

class ArticlePriceObserver
{
    public function deleted(ArticlePrice $price): void
    {
        $article = $price->article;

        if (! $article) {
            return;
        }

        // La reversión de stock y PPP ya la maneja InventoryTransactionDetailObserver::deleted
        // cuando se elimina el detalle de transacción. Este observer solo existe como respaldo
        // para borrados directos de ArticlePrice que no pasen por el detalle.
        // No revertimos aquí para evitar doble reversión.
    }
}
