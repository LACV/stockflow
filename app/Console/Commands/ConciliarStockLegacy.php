<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConciliarStockLegacy extends Command
{
    protected $signature = 'inventario:conciliar-stock
        {--dry-run : Muestra qué artículos cambiarían sin escribir nada}
        {--source= : Ruta del CSV con el stock legacy. Por defecto database/legacy/stock_legacy.csv}
        {--desde=2026-08-01 : Fecha desde la que se consideran entradas NUEVAS (type=entry)}';

    protected $description = 'Calcula el stock de los artículos legacy como base_legacy + entradas nuevas (facturas de la app). No toca artículos nuevos ni salidas/ajustes.';

    public function handle(): int
    {
        $source = $this->option('source') ?: database_path('legacy/stock_legacy.csv');

        if (! file_exists($source)) {
            $this->error("No se encuentra el archivo de referencia: {$source}");

            return self::FAILURE;
        }

        $legacy = $this->parseCsv($source);

        if (count($legacy) === 0) {
            $this->warn('El archivo de referencia está vacío. Nada que hacer.');

            return self::SUCCESS;
        }

        $desde = $this->option('desde');
        $this->info("Entradas nuevas desde: {$desde}");

        $entradas = $this->entradasNuevasPorArticulo($desde);

        $candidatos = Article::whereIn('id', array_keys($legacy))->get();

        $cambios = [];
        foreach ($candidatos as $article) {
            $id = (int) $article->id;
            $base = $legacy[$id];
            $suma = $entradas[$id] ?? 0;
            $nuevo = max(0, $base + $suma);

            $cambios[] = [
                'id' => $id,
                'code' => $article->code,
                'name' => $article->name,
                'actual' => (float) $article->stock_quantity,
                'base' => $base,
                'entradas' => $suma,
                'nuevo' => $nuevo,
            ];
        }

        $cambios = array_filter($cambios, fn ($c) => abs($c['nuevo'] - $c['actual']) > 0.0001);

        if (count($cambios) === 0) {
            $this->info('Ningún artículo legacy cambia con esta conciliación. Nada que corregir.');

            return self::SUCCESS;
        }

        $this->info('Se actualizarían '.count($cambios).' artículos legacy (base + entradas nuevas):');

        if ($this->option('dry-run')) {
            foreach ($cambios as $c) {
                $this->line("  [{$c['id']}] {$c['code']} {$c['name']}: {$this->fmt($c['actual'])} -> {$this->fmt($c['nuevo'])} (base {$this->fmt($c['base'])} + entradas {$this->fmt($c['entradas'])})");
            }
            $this->info('Modo dry-run: no se escribió nada.');

            return self::SUCCESS;
        }

        $backup = [
            'generated_at' => now()->toDateTimeString(),
            'command' => 'inventario:conciliar-stock',
            'source' => $source,
            'desde' => $desde,
            'articles' => array_values($cambios),
        ];

        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir.'/conciliacion_stock_legacy_'.now()->format('Ymd_His').'.json';
        file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        $this->info('Respaldo creado: '.$file);

        if (! $this->confirm('Aplicar la conciliación a '.count($cambios).' artículos?')) {
            $this->warn('Operación cancelada.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($cambios) {
            foreach ($cambios as $c) {
                Article::where('id', $c['id'])->update(['stock_quantity' => $c['nuevo']]);
            }
        });

        $this->info('Conciliación aplicada.');

        $restantes = Article::where('stock_quantity', '<', 0)->count();
        $this->line('Verificación: artículos con stock negativo restantes: '.$restantes);

        return self::SUCCESS;
    }

    private function entradasNuevasPorArticulo(string $desde): array
    {
        $rows = DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('t.type', 'entry')
            ->where('t.status', 'active')
            ->where('t.transaction_date', '>=', $desde)
            ->groupBy('d.article_id')
            ->select('d.article_id', DB::raw('SUM(d.quantity) as total'))
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->article_id] = (float) $r->total;
        }

        return $map;
    }

    private function parseCsv(string $path): array
    {
        $map = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $map;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return $map;
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) {
                continue;
            }
            $id = (int) $row[0];
            $qty = (float) $row[1];
            $map[$id] = $qty;
        }
        fclose($handle);

        return $map;
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }
}
