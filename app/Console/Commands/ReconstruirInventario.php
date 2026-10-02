<?php

namespace App\Console\Commands;

use App\Models\MonthlyClosure;
use App\Services\MonthlyClosureService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconstruirInventario extends Command
{
    protected $signature = 'inventario:reconstruir-inventario
        {--dry-run : Muestra el plan sin escribir nada}';

    protected $description = 'Reconstruye balances y snapshots mensuales (en cadena desde el mes anterior) y reconcilia el inventario vivo con el último cierre + movimientos posteriores. Crea respaldo previo.';

    public function handle(): int
    {
        $closures = MonthlyClosure::where('is_closed', true)
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->get();

        if ($closures->isEmpty()) {
            $this->warn('No hay cierres mensuales que reconstruir.');

            return self::SUCCESS;
        }

        $last = $closures->last();

        $this->info('Cierres a reconstruir: '.$closures->count());
        $this->info('Último cierre: '.$last->month.'/'.$last->year);

        if ($this->option('dry-run')) {
            $this->line('  - Se reescribirán monthly_closure_balances y monthly_closure_snapshots de cada cierre.');
            $this->line('  - Se recalculará el inventario vivo (articles) desde el último cierre + movimientos posteriores.');
            $this->info('Modo dry-run: no se escribió nada.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Se creará un respaldo y se reescribirán balances, snapshots y el inventario vivo. ¿Continuar?')) {
            $this->warn('Operación cancelada.');

            return self::SUCCESS;
        }

        $backup = $this->createBackup($closures);
        $this->info('Respaldo creado: '.$backup);

        $service = app(MonthlyClosureService::class);

        foreach ($closures as $closure) {
            $this->info('  Reconstruyendo '.$closure->month.'/'.$closure->year.' ...');
            $service->regenerateBalances($closure);
            $service->regenerateSnapshot($closure);
        }

        $updated = $service->reconcileLiveInventory($last);
        $this->info('Inventario vivo reconciliado: '.$updated.' artículos actualizados.');

        $zeroed = $service->zeroStalePppOnDepleted();
        $this->info('PPPs de artículos agotados puestos en 0: '.$zeroed.'.');

        $this->info('Listo. Verifique el dashboard y descargue un Excel mensual para validar.');

        return self::SUCCESS;
    }

    private function createBackup(Collection $closures): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $stamp = now()->format('Ymd_His');
        $base = $dir.'/reconstruccion_inventario_'.$stamp;
        $meta = $base.'_meta.json';

        file_put_contents($meta, json_encode([
            'generated_at' => now()->toDateTimeString(),
            'closures' => $closures->pluck('id')->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        $this->dumpTable($base.'_snapshots.jsonl', 'monthly_closure_snapshots', [
            'monthly_closure_id', 'article_id', 'stock_quantity', 'average_cost', 'total_value',
        ]);
        $this->dumpTable($base.'_balances.jsonl', 'monthly_closure_balances', [
            'monthly_closure_id', 'article_id', 'stock', 'average_cost', 'total_value',
        ]);
        $this->dumpTable($base.'_articles.jsonl', 'articles', [
            'id', 'stock_quantity', 'current_average_cost',
        ]);

        return $meta;
    }

    private function dumpTable(string $file, string $table, array $columns): void
    {
        $handle = fopen($file, 'w');

        DB::table($table)
            ->select($columns)
            ->orderBy('id')
            ->cursor()
            ->each(function ($row) use ($handle): void {
                fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE)."\n");
            });

        fclose($handle);
    }
}
