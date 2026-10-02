<?php

namespace App\Console\Commands;

use App\Models\MonthlyClosure;
use App\Services\MonthlyClosureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sincroniza el inventario VIVO (articles.stock_quantity/current_average_cost)
 * con el ultimo cierre mensual cerrado + las transacciones reales posteriores
 * a ese cierre, en cadena (MonthlyClosureService::reconcileLiveInventory).
 *
 * Usar despues de corregir un cierre historico (ej. inventario:corregir-cierre-
 * julio-2026), para que el inventario que ven los usuarios al operar quede
 * alineado con la correccion. No modifica snapshots/balances, solo `articles`.
 *
 * Seguro de re-ejecutar. Crea respaldo de `articles` antes de escribir.
 */
class ReconciliarInventarioVivo extends Command
{
    protected $signature = 'inventario:reconciliar-inventario-vivo
        {--dry-run : Muestra cuantos articulos cambiarian, sin escribir nada}';

    protected $description = 'Sincroniza articles.stock_quantity/current_average_cost con el ultimo cierre cerrado + transacciones reales posteriores.';

    public function handle(): int
    {
        $last = MonthlyClosure::where('is_closed', true)
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->first();

        if (! $last) {
            $this->warn('No hay ningun cierre mensual cerrado.');

            return self::SUCCESS;
        }

        $this->info("Ultimo cierre cerrado: {$last->month}/{$last->year} (closure {$last->id})");

        $preview = $this->previewChanges($last);
        $this->info('Articulos con diferencia entre vivo y (cierre + movimientos posteriores): '.count($preview));

        foreach (array_slice($preview, 0, 30) as $p) {
            $this->line("  id={$p['id']} | vivo stock={$p['liveStock']} avg={$p['liveAvg']} -> nuevo stock={$p['newStock']} avg={$p['newAvg']}");
        }
        if (count($preview) > 30) {
            $this->line('  ... y '.(count($preview) - 30).' mas.');
        }

        if ($this->option('dry-run')) {
            $this->info('Modo dry-run: no se escribio nada.');

            return self::SUCCESS;
        }

        if (empty($preview)) {
            $this->info('Nada que reconciliar, el inventario vivo ya coincide.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Se actualizara stock/costo promedio de '.count($preview).' articulos en el inventario vivo. ¿Continuar?')) {
            $this->warn('Operacion cancelada.');

            return self::SUCCESS;
        }

        $this->backup();

        $service = app(MonthlyClosureService::class);
        $updated = $service->reconcileLiveInventory($last);
        $zeroed = $service->zeroStalePppOnDepleted();

        $this->info("Inventario vivo reconciliado: {$updated} articulos actualizados.");
        $this->info("PPP puesto en 0 para articulos agotados: {$zeroed}.");
        $this->info('Listo. Verifique el modulo de Articulos y el dashboard.');

        return self::SUCCESS;
    }

    private function previewChanges(MonthlyClosure $last): array
    {
        $cutoff = \Carbon\Carbon::create($last->year, $last->month, 1)->endOfMonth()->toDateString();

        $snap = DB::table('monthly_closure_snapshots')
            ->where('monthly_closure_id', $last->id)
            ->select('article_id', 'stock_quantity', 'average_cost')
            ->get()
            ->keyBy('article_id');

        $post = DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('t.status', 'active')
            ->where('t.transaction_date', '>', $cutoff)
            ->orderBy('t.transaction_date')->orderBy('d.created_at')
            ->select('d.article_id', 't.type', 'd.quantity', 'd.unit_cost')
            ->get()
            ->groupBy('article_id');

        $notInSnap = DB::table('articles')->where('is_active', true)->whereNotIn('id', $snap->keys())->pluck('id');
        $allIds = $snap->keys()->merge($notInSnap)->unique();

        $live = DB::table('articles')->whereIn('id', $allIds)->get()->keyBy('id');

        $diffs = [];
        foreach ($allIds as $articleId) {
            $row = $snap->get($articleId);
            $stock = $row ? (float) $row->stock_quantity : 0.0;
            $avg = $row ? (float) $row->average_cost : 0.0;

            foreach ($post->get($articleId, collect()) as $mov) {
                $type = strtoupper($mov->type);
                $qty = (float) $mov->quantity;
                $cost = (float) $mov->unit_cost;
                if ($type === 'ENTRY' || ($type === 'ADJUSTMENT' && $qty > 0)) {
                    $newStock = $stock + $qty;
                    $avg = $newStock > 0 ? (($stock * $avg) + ($qty * $cost)) / $newStock : $cost;
                    $stock = $newStock;
                } else {
                    $stock = max(0, $stock - abs($qty));
                    if ($stock <= 0) {
                        $avg = 0;
                    }
                }
            }
            $stock = round(max(0, $stock), 4);
            $avg = round(abs($avg), 4);

            $liveRow = $live->get($articleId);
            if (! $liveRow) {
                continue;
            }
            $dStock = round((float) $liveRow->stock_quantity - $stock, 2);
            $dAvg = round((float) $liveRow->current_average_cost - $avg, 2);
            if (abs($dStock) > 0.01 || abs($dAvg) > 0.5) {
                $diffs[] = [
                    'id' => $articleId,
                    'liveStock' => $liveRow->stock_quantity,
                    'liveAvg' => $liveRow->current_average_cost,
                    'newStock' => $stock,
                    'newAvg' => $avg,
                ];
            }
        }

        return $diffs;
    }

    private function backup(): void
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $dir.'/reconciliar_inventario_vivo_'.now()->format('Ymd_His').'.jsonl';
        $handle = fopen($file, 'w');
        DB::table('articles')
            ->select('id', 'stock_quantity', 'current_average_cost')
            ->orderBy('id')
            ->cursor()
            ->each(function ($row) use ($handle): void {
                fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE)."\n");
            });
        fclose($handle);

        $this->info('Respaldo creado: '.$file);
    }
}
