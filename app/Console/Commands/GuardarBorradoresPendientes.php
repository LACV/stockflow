<?php

namespace App\Console\Commands;

use App\Models\InventoryTransaction;
use App\Models\MonthlyClosure;
use App\Services\MovementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Regulariza despachos de salida que quedaron en borrador aunque las salidas ya
 * ocurrieron fisicamente.
 *
 * - Una linea SIN stock que ya esta registrada como salida activa en el mismo mes
 *   (mismo articulo, cantidad, destino y empleado) es la misma salida: se saca del
 *   borrador para no contarla dos veces. Con stock suficiente nunca se saca.
 * - Las demas lineas se guardan SIN exigir stock: el stock del articulo queda en 0
 *   (nunca negativo) y el PPP no se modifica, porque una salida no lo cambia.
 *
 * Solo actua sobre los ids indicados, con respaldo previo y --dry-run.
 */
class GuardarBorradoresPendientes extends Command
{
    protected $signature = 'inventario:guardar-borradores-pendientes
        {--ids= : Ids de los despachos en borrador, separados por coma}
        {--dry-run : Muestra el plan sin escribir nada}';

    protected $description = 'Saca de los borradores las lineas ya registradas y guarda el resto sin exigir stock.';

    public function handle(MovementService $movement): int
    {
        $ids = collect(explode(',', (string) $this->option('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->error('Indique los despachos con --ids=1,2,3');

            return self::FAILURE;
        }

        $drafts = InventoryTransaction::whereIn('id', $ids)
            ->where('type', 'exit')
            ->where('status', 'inactive')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($ids->diff($drafts->pluck('id')) as $missing) {
            $this->warn("Despacho {$missing}: no existe, no es una salida o ya no esta en borrador. Se omite.");
        }

        $claimed = [];
        $simStock = [];
        $plan = [];

        foreach ($drafts as $draft) {
            $date = Carbon::parse($draft->transaction_date);

            if (MonthlyClosure::where('month', $date->month)->where('year', $date->year)->where('is_closed', true)->exists()) {
                $this->warn("Despacho {$draft->id} ({$date->format('d/m/Y')}): su mes esta cerrado. Se omite.");

                continue;
            }

            $entry = ['draft' => $draft, 'duplicates' => [], 'forced' => [], 'normal' => 0];

            foreach ($draft->details()->with('article')->orderBy('id')->get() as $line) {
                $articleId = $line->article_id;
                $stock = $simStock[$articleId] ?? (float) $line->article->stock_quantity;
                $qty = (float) $line->quantity;

                // Con stock suficiente la linea es una salida real, aunque otra se le parezca:
                // la misma persona puede retirar lo mismo varias veces en el mes.
                if ($qty <= $stock) {
                    $simStock[$articleId] = $stock - $qty;
                    $entry['normal']++;

                    continue;
                }

                // Sin stock: si ya esta registrada como salida activa, es la misma salida.
                $match = $this->findRegisteredTwin($draft, $line, $claimed);

                if ($match) {
                    $claimed[] = $match->id;
                    $entry['duplicates'][] = ['line' => $line, 'twin' => $match];

                    continue;
                }

                $simStock[$articleId] = 0.0;
                $entry['forced'][] = ['line' => $line, 'short' => $qty - $stock];
            }

            $plan[] = $entry;
        }

        $this->report($plan);

        if ($this->option('dry-run')) {
            $this->info('Modo dry-run: no se escribio nada.');

            return self::SUCCESS;
        }

        if ($plan === []) {
            return self::SUCCESS;
        }

        $backup = 'backups/borradores-pendientes-'.now()->format('Ymd_His').'.json';
        Storage::disk('local')->put($backup, json_encode(
            collect($plan)->map(fn ($e) => [
                'despacho' => $e['draft']->id,
                'lineas' => $e['draft']->details()->get()->toArray(),
            ])->all(),
            JSON_PRETTY_PRINT
        ));
        $this->info('Respaldo creado: storage/app/private/'.$backup);

        try {
            DB::transaction(function () use ($plan, $movement) {
                foreach ($plan as $entry) {
                    $draft = $entry['draft'];

                    foreach ($entry['duplicates'] as $dup) {
                        $dup['line']->delete();
                    }

                    if ($draft->details()->doesntExist()) {
                        $this->warn("Despacho {$draft->id}: quedo sin lineas, se deja en borrador.");

                        continue;
                    }

                    $result = $movement->finalizeTransaction($draft->fresh(), skipStockValidation: true);

                    if (! $result['ok']) {
                        throw new \RuntimeException("Despacho {$draft->id}: {$result['title']} - {$result['body']}");
                    }

                    foreach ($entry['forced'] as $forced) {
                        Log::info('inventory.draft_saved_without_stock', [
                            'despacho' => $draft->id,
                            'articulo' => $forced['line']->article->code,
                            'cantidad' => (float) $forced['line']->quantity,
                            'faltaba' => $forced['short'],
                        ]);
                    }

                    $this->info("Despacho {$draft->id}: guardado.");
                }
            });
        } catch (\Throwable $e) {
            $this->error('ROLLBACK: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Salida activa del mismo mes con el mismo articulo, cantidad, destino y empleado.
     */
    private function findRegisteredTwin(InventoryTransaction $draft, $line, array $claimed): ?object
    {
        $date = Carbon::parse($draft->transaction_date);

        return DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('t.type', 'exit')
            ->where('t.status', 'active')
            ->where('t.id', '!=', $draft->id)
            ->whereMonth('t.transaction_date', $date->month)
            ->whereYear('t.transaction_date', $date->year)
            ->where('d.article_id', $line->article_id)
            ->where('d.quantity', $line->quantity)
            ->where(fn ($q) => $line->destination_id === null ? $q->whereNull('d.destination_id') : $q->where('d.destination_id', $line->destination_id))
            ->where(fn ($q) => $line->employee_id === null ? $q->whereNull('d.employee_id') : $q->where('d.employee_id', $line->employee_id))
            ->when($claimed !== [], fn ($q) => $q->whereNotIn('d.id', $claimed))
            ->orderBy('d.id')
            ->select('d.id', 't.id as tx_id', 't.transaction_date')
            ->first();
    }

    private function report(array $plan): void
    {
        foreach ($plan as $entry) {
            $draft = $entry['draft'];
            $this->newLine();
            $this->info("Despacho {$draft->id} ({$draft->transaction_date->format('d/m/Y')}): ".$draft->details()->count().' lineas');

            foreach ($entry['duplicates'] as $dup) {
                $l = $dup['line'];
                $this->line("  SE SACA (ya registrada en el despacho {$dup['twin']->tx_id}): [{$l->article->code}] {$l->article->name} x ".(float) $l->quantity);
            }

            foreach ($entry['forced'] as $f) {
                $l = $f['line'];
                $this->line("  SE GUARDA SIN STOCK (faltan {$f['short']}): [{$l->article->code}] {$l->article->name} x ".(float) $l->quantity);
            }

            $this->line("  Se guardan con stock normal: {$entry['normal']} lineas");
        }
    }
}
