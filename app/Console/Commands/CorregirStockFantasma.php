<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deja en cero el stock/costo de un articulo que quedo con "stock fantasma":
 * existencias en `articles` sin ningun movimiento activo que las respalde
 * (ej. se borro la entrada y luego se reabrio la salida, que devolvio la unidad).
 *
 * Se niega a actuar si el articulo tiene movimientos activos, precios o snapshots
 * de cierre, porque en ese caso el stock tiene respaldo y no es fantasma.
 * Crea respaldo JSON antes de escribir.
 */
class CorregirStockFantasma extends Command
{
    protected $signature = 'inventario:corregir-stock-fantasma
        {codigo : Codigo exacto del articulo}
        {--dry-run : Muestra el plan sin escribir nada}';

    protected $description = 'Pone en 0 el stock y costo promedio de un articulo con stock fantasma (sin respaldo en movimientos, precios o snapshots).';

    public function handle(): int
    {
        $article = DB::table('articles')->where('code', $this->argument('codigo'))->first();

        if (! $article) {
            $this->error("No existe ningun articulo con codigo '{$this->argument('codigo')}'.");

            return self::FAILURE;
        }

        $activeMovs = DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('d.article_id', $article->id)
            ->where('t.status', 'active')
            ->count();

        // Stock inicial legitimo (migracion/cierres) tambien carece de movimientos:
        // si hay precios o snapshots de cierre, el stock tiene respaldo.
        $backed = DB::table('article_prices')->where('article_id', $article->id)->exists()
            || DB::table('monthly_closure_snapshots')->where('article_id', $article->id)->exists();

        $this->info("Articulo: {$article->code} - {$article->name} {$article->reference} (id {$article->id})");
        $this->line("  stock={$article->stock_quantity} | costo promedio={$article->current_average_cost} | movimientos activos={$activeMovs}");

        if ($activeMovs > 0 || $backed) {
            $this->error('El articulo tiene movimientos, precios o snapshots que respaldan su stock: no es fantasma. No se toca.');

            return self::FAILURE;
        }

        if ((float) $article->stock_quantity == 0.0 && (float) $article->current_average_cost == 0.0) {
            $this->info('Ya esta en 0. Nada que hacer.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Modo dry-run: no se escribio nada.');

            return self::SUCCESS;
        }

        $file = 'backups/stock-fantasma-'.$article->id.'-'.now()->format('Ymd_His').'.json';
        Storage::disk('local')->put($file, json_encode($article, JSON_PRETTY_PRINT));
        $this->info('Respaldo creado: storage/app/private/'.$file);

        DB::table('articles')->where('id', $article->id)->update([
            'stock_quantity' => 0,
            'current_average_cost' => 0,
            'updated_at' => now(),
        ]);

        $this->info('Listo: stock y costo promedio en 0.');

        return self::SUCCESS;
    }
}
