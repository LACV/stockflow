<?php

namespace App\Console\Commands;

use App\Models\MonthlyClosure;
use App\Services\MonthlyClosureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retira un articulo/transacciones de prueba que hayan quedado con status
 * 'active' mezcladas con datos reales (ej. TEST99 de bk/pasos.md, 2026-09-03,
 * que infló el cierre de julio/agosto en $100,240.36) y regenera en cadena
 * los cierres mensuales cerrados afectados desde el primer mes tocado.
 *
 * No toca articulos reales ni transacciones que no pertenezcan al articulo
 * indicado. Crea respaldo JSON antes de escribir. Seguro de re-ejecutar:
 * si el articulo no existe, no hace nada.
 */
class LimpiarArticuloPrueba extends Command
{
    protected $signature = 'inventario:limpiar-articulo-prueba
        {codigo=TEST99 : Codigo exacto del articulo de prueba a retirar}
        {--dry-run : Muestra el plan sin escribir nada}';

    protected $description = 'Retira un articulo de prueba y sus transacciones, y regenera en cadena los cierres mensuales cerrados que se vieron afectados.';

    public function handle(): int
    {
        $codigo = (string) $this->argument('codigo');

        $article = DB::table('articles')->where('code', $codigo)->first();

        if (! $article) {
            $this->info("No existe ningun articulo con codigo '{$codigo}'. Nada que limpiar.");

            return self::SUCCESS;
        }

        $details = DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('d.article_id', $article->id)
            ->select('d.id as detail_id', 't.id as tx_id', 't.type', 't.status', 't.transaction_date')
            ->get();

        if ($details->isEmpty()) {
            $this->warn("El articulo '{$codigo}' (id {$article->id}) no tiene transacciones. Se borrara solo el articulo.");
        }

        $txIds = $details->pluck('tx_id')->unique()->values();

        $affectedMonths = $details
            ->map(fn ($d) => \Carbon\Carbon::parse($d->transaction_date))
            ->map(fn ($c) => ['month' => $c->month, 'year' => $c->year])
            ->unique(fn ($m) => $m['month'].'-'.$m['year'])
            ->values();

        $closuresToRegenerate = MonthlyClosure::where('is_closed', true)
            ->where(function ($q) use ($affectedMonths) {
                foreach ($affectedMonths as $m) {
                    $q->orWhere(function ($qq) use ($m) {
                        $qq->where('year', '>', $m['year'])
                            ->orWhere(function ($qqq) use ($m) {
                                $qqq->where('year', $m['year'])->where('month', '>=', $m['month']);
                            });
                    });
                }
            })
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        $this->info("Articulo: {$article->code} - {$article->name} (id {$article->id})");
        $this->info('Transacciones a eliminar: '.$txIds->count().' ('.$details->count().' detalles)');
        foreach ($details as $d) {
            $this->line("  tx {$d->tx_id} | {$d->type} | {$d->transaction_date} | status={$d->status}");
        }
        $this->info('Cierres cerrados a regenerar (en cadena, desde el primer mes afectado): '.$closuresToRegenerate->count());
        foreach ($closuresToRegenerate as $c) {
            $before = (float) DB::table('monthly_closure_snapshots')->where('monthly_closure_id', $c->id)->sum('total_value');
            $this->line("  closure {$c->id} | {$c->month}/{$c->year} | total ANTES: ".number_format($before, 2));
        }

        if ($this->option('dry-run')) {
            $this->info('Modo dry-run: no se escribio nada.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Se creara un respaldo, se borrara el articulo/transacciones y se regeneraran los cierres listados arriba. ¿Continuar?')) {
            $this->warn('Operacion cancelada.');

            return self::SUCCESS;
        }

        $backupFile = $this->createBackup($article, $txIds, $closuresToRegenerate);
        $this->info('Respaldo creado: '.$backupFile);

        DB::beginTransaction();

        try {
            DB::table('inventory_transaction_details')->where('article_id', $article->id)->delete();

            // Solo borra transacciones que quedaron sin ningun detalle (no comparte
            // factura con articulos reales); si alguna tx del articulo de prueba
            // tenia tambien lineas reales, esa tx se conserva intacta.
            $orphanTxIds = DB::table('inventory_transactions as t')
                ->whereIn('t.id', $txIds)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('inventory_transaction_details as d')
                        ->whereColumn('d.inventory_transaction_id', 't.id');
                })
                ->pluck('t.id');

            DB::table('inventory_transactions')->whereIn('id', $orphanTxIds)->delete();

            DB::table('monthly_closure_snapshots')->where('article_id', $article->id)->delete();
            DB::table('monthly_closure_balances')->where('article_id', $article->id)->delete();
            DB::table('articles')->where('id', $article->id)->delete();

            $service = app(MonthlyClosureService::class);

            foreach ($closuresToRegenerate as $closure) {
                $this->info("  Regenerando {$closure->month}/{$closure->year} ...");
                $service->regenerateBalances($closure->fresh());
                $service->regenerateSnapshot($closure->fresh());
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('ROLLBACK: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Totales DESPUES de limpiar y regenerar:');
        foreach ($closuresToRegenerate as $c) {
            $after = (float) DB::table('monthly_closure_snapshots')->where('monthly_closure_id', $c->id)->sum('total_value');
            $n = DB::table('monthly_closure_snapshots')->where('monthly_closure_id', $c->id)->count();
            $this->line("  closure {$c->id} | {$c->month}/{$c->year} | total: ".number_format($after, 2)." | n={$n}");
        }

        $this->info('Listo. Verifique /admin/preview-excel del mes afectado y descargue el Excel para validar.');

        return self::SUCCESS;
    }

    private function createBackup(object $article, \Illuminate\Support\Collection $txIds, \Illuminate\Support\Collection $closures): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $stamp = now()->format('Ymd_His');
        $file = $dir."/limpieza_articulo_prueba_{$article->code}_{$stamp}.json";

        $backup = [
            'generated_at' => now()->toDateTimeString(),
            'articulo_code' => $article->code,
            'article' => (array) $article,
            'transactions' => DB::table('inventory_transactions')->whereIn('id', $txIds)->get()->toArray(),
            'transaction_details' => DB::table('inventory_transaction_details')->where('article_id', $article->id)->get()->toArray(),
            'snapshots' => DB::table('monthly_closure_snapshots')->whereIn('monthly_closure_id', $closures->pluck('id'))->get()->toArray(),
            'balances' => DB::table('monthly_closure_balances')->whereIn('monthly_closure_id', $closures->pluck('id'))->get()->toArray(),
        ];

        file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $file;
    }
}
