<?php

namespace App\Services;

use App\Models\MonthlyClosure;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyMovementsExcelService
{
    public function stream(int $month, int $year): StreamedResponse
    {
        $filename = $this->filename($month, $year);

        $path = tempnam(sys_get_temp_dir(), 'inventario_').'.xlsx';
        $this->writeToFile($path, $month, $year);

        return response()->streamDownload(function () use ($path): void {
            readfile($path);
            @unlink($path);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function filename(int $month, int $year): string
    {
        return 'Inventario'.Carbon::create($year, $month, 1)->format('F').$year.'.xlsx';
    }

    public function writeToFile(string $path, int $month, int $year): void
    {
        $data = $this->collectData($month, $year);
        $label = $data['monthLabel'];

        $writer = new Writer;
        $writer->openToFile($path);

        $headerStyle = (new Style)
            ->setFontBold()
            ->setFontColor('FFFFFF')
            ->setBackgroundColor('4472C4');

        $this->writeSheet($writer, "Entradas {$label}-{$year}", $data['entradasHeaders'], $data['entradas'], $data['entradasWidths'], $headerStyle, true);
        $this->writeSheet($writer, "Salida {$label}-{$year}", $data['salidasHeaders'], $data['salidas'], $data['salidasWidths'], $headerStyle);
        $this->writeSheet($writer, "Movimientos {$label}-{$year}", $data['movimientosHeaders'], $data['movimientos'], $data['movimientosWidths'], $headerStyle);

        $writer->close();
    }

    private function writeSheet(Writer $writer, string $name, array $headers, array $rows, array $widths, Style $headerStyle, bool $first = false): void
    {
        if ($first) {
            $sheet = $writer->getCurrentSheet();
        } else {
            $writer->addNewSheetAndMakeItCurrent();
            $sheet = $writer->getCurrentSheet();
        }

        $sheet->setName($name);

        foreach ($widths as $column => $width) {
            $sheet->setColumnWidth($width, $column);
        }

        $writer->addRow(Row::fromValues($headers, $headerStyle));

        foreach ($rows as $row) {
            $writer->addRow($row instanceof Row ? $row : Row::fromValues($row));
        }
    }

    private function accountingStyle(): Style
    {
        return (new Style)->setFormat('_($* #,##0.00_);_($* (#,##0.00);_($* "-"??_);_(@_)');
    }

    private function monthSnapshot(int $month, int $year): Collection
    {
        $closure = MonthlyClosure::query()
            ->where('month', $month)
            ->where('year', $year)
            ->where('is_closed', true)
            ->first();

        if (! $closure) {
            return collect();
        }

        $rows = DB::table('monthly_closure_snapshots')
            ->where('monthly_closure_id', $closure->id)
            ->select('article_id', 'stock_quantity', 'average_cost', 'total_value')
            ->get();

        if ($rows->isEmpty()) {
            $rows = $closure->balances()
                ->select('article_id', 'stock', 'average_cost', 'total_value')
                ->get()
                ->map(fn ($b) => (object) [
                    'article_id' => $b->article_id,
                    'stock_quantity' => $b->stock,
                    'average_cost' => $b->average_cost,
                    'total_value' => $b->total_value,
                ]);
        }

        return $rows->mapWithKeys(fn ($r) => [$r->article_id => [
            'stock' => (float) $r->stock_quantity,
            'avg' => (float) $r->average_cost,
            'total' => (float) $r->total_value,
        ]]);
    }

    /**
     * Borradores del mes con lineas: no cuentan en entradas/salidas hasta guardarse.
     *
     * @return array{count: int, value: float}
     */
    public function draftSummary(int $month, int $year): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $row = DB::table('inventory_transactions as t')
            ->join('inventory_transaction_details as d', 'd.inventory_transaction_id', '=', 't.id')
            ->where('t.status', 'inactive')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->selectRaw('count(distinct t.id) as n, coalesce(sum(d.total_cost), 0) as v')
            ->first();

        return ['count' => (int) $row->n, 'value' => (float) $row->v];
    }

    public const PREVIEW_CACHE_TTL_CLOSED = 86400;

    public const PREVIEW_CACHE_TTL_OPEN = 300;

    public static function previewCacheKey(int $closureId): string
    {
        return "preview-excel-data.{$closureId}";
    }

    public function previewArrays(int $month, int $year): array
    {
        $data = $this->collectData($month, $year);

        $toArray = fn ($rows) => array_map(
            fn ($row) => $row instanceof Row
                ? array_map(fn ($cell) => $cell->getValue(), $row->getCells())
                : $row,
            $rows,
        );

        return [
            'monthLabel' => $data['monthLabel'],
            'entradasHeaders' => $data['entradasHeaders'],
            'salidasHeaders' => $data['salidasHeaders'],
            'movimientosHeaders' => $data['movimientosHeaders'],
            'entradas' => $toArray($data['entradas']),
            'salidas' => $toArray($data['salidas']),
            'movimientos' => $toArray($data['movimientos']),
        ];
    }

    public function collectData(int $month, int $year): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $monthLabel = Carbon::create($year, $month, 1)->format('F');

        $monthIsClosed = MonthlyClosure::query()
            ->where('month', $month)
            ->where('year', $year)
            ->where('is_closed', true)
            ->exists();

        $prevMonth = Carbon::create($year, $month, 1)->subMonth();
        $selSnap = $this->monthSnapshot($month, $year);
        $prevSnap = $this->monthSnapshot($prevMonth->month, $prevMonth->year);

        $money = $this->accountingStyle();
        $totalRow = fn (array $values, array $moneyCols): Row => Row::fromValuesWithStyles(
            $values,
            null,
            array_fill_keys($moneyCols, $money)
        );

        $details = DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->join('articles as a', 'a.id', '=', 'd.article_id')
            ->leftJoin('types as ty', 'ty.id', '=', 'a.type_id')
            ->leftJoin('destinations as dest', 'dest.id', '=', 'd.destination_id')
            ->leftJoin('employees as emp', 'emp.id', '=', 'd.employee_id')
            ->where('t.status', 'active')
            ->whereBetween('t.transaction_date', [$monthStart, $monthEnd])
            ->orderBy('d.created_at', 'asc')
            ->orderBy('d.id', 'asc')
            ->select(
                'd.article_id',
                'a.code',
                'a.name as product',
                'a.reference',
                'ty.name as clase',
                't.type as tx_type',
                't.purchase_order',
                't.invoice_number',
                't.entry_number',
                'd.quantity',
                'd.unit_cost',
                'dest.code as dest_code',
                'dest.name as dest_name',
                'emp.name as emp_name'
            )
            ->get();

        $n = fn ($v): float => round((float) $v, 4);
        $productName = function (string $name, ?string $reference = null): string {
            $name = trim($name);
            $reference = trim((string) $reference);

            return $reference === '' ? $name : $name.' - '.$reference;
        };
        $articles = [];
        $entradas = [];
        $salidas = [];

        foreach ($details as $d) {
            $id = (int) $d->article_id;

            if (! isset($articles[$id])) {
                $pb = $prevSnap->get($id);

                $articles[$id] = [
                    'code' => (string) $d->code,
                    'product' => $productName((string) $d->product, (string) $d->reference),
                    'clase' => (string) $d->clase,
                    'stock' => $pb ? $pb['stock'] : 0.0,
                    'ppp' => $pb ? $pb['avg'] : 0.0,
                    'cEnt' => 0.0,
                    'vCom' => 0.0,
                    'cSal' => 0.0,
                    'vSal' => 0.0,
                ];
            }

            $r = &$articles[$id];
            $type = strtoupper($d->tx_type);
            $qty = (float) $d->quantity;
            $cost = (float) $d->unit_cost;

            if ($type === 'ENTRY' || ($type === 'ADJUSTMENT' && $qty > 0)) {
                $r['cEnt'] += $qty;
                $r['vCom'] += $qty * $cost;

                $entradas[] = [
                    'factura' => (string) $d->invoice_number,
                    'values' => [
                        'code' => $r['code'],
                        'product' => $r['product'],
                        'cant' => $n($qty),
                        'unit' => $n($cost),
                        'total' => $n($qty * $cost),
                        'ordenEnt' => (string) $d->entry_number,
                        'ordenComp' => (string) $d->purchase_order,
                        'factura' => (string) $d->invoice_number,
                        'clase' => $r['clase'],
                    ],
                ];

                $newStock = $r['stock'] + $qty;
                $r['ppp'] = $newStock > 0
                    ? (($r['stock'] * $r['ppp']) + ($qty * $cost)) / $newStock
                    : $cost;
                $r['stock'] = $newStock;
            } else {
                // Las salidas se valoran al PPP vigente de la cadena (mismo metodo que
                // genera los snapshots), para que INI + COMPRA - SALIDA sea coherente
                // con el FINAL del snapshot.
                // Mes abierto: una salida sin cobertura en la cadena (sin PPP) se valora al
                // costo guardado en su linea en vez de $0. Los meses cerrados ya se
                // entregaron (contabilidad/DIAN) y conservan su valor original.
                $unit = $r['ppp'] > 0 || $monthIsClosed ? $r['ppp'] : $cost;

                // Un ajuste negativo trae cantidad negativa. En un mes abierto se muestra
                // como una salida positiva (igual que el stock, que ya usa abs()); asi
                // inicial + compras - salidas = final. Solo cambia la presentacion: el PPP
                // no depende de este signo. Los meses cerrados conservan su valor original.
                $qty = $monthIsClosed ? $qty : abs($qty);
                $total = $qty * $unit;

                $r['cSal'] += $qty;
                $r['vSal'] += $total;

                $salidas[] = [
                    'clase' => $r['clase'],
                    'id' => $id,
                    'values' => [
                        'code' => $r['code'],
                        'product' => $r['product'],
                        'cant' => $n($qty),
                        'unit' => $n($unit),
                        'total' => $n($total),
                        'codDest' => (string) $d->dest_code,
                        'dest' => (string) $d->dest_name,
                        'clase' => $r['clase'],
                        'empleado' => (string) $d->emp_name,
                    ],
                ];

                $r['stock'] = max(0, $r['stock'] - abs($qty));
                if ($r['stock'] <= 0) {
                    $r['ppp'] = 0;
                }
            }
        }

        unset($r);

        $rowIds = array_values(array_unique(array_merge(
            array_keys($articles),
            $selSnap->keys()->all()
        )));
        sort($rowIds);

        $missing = array_filter($rowIds, fn ($id) => ! isset($articles[$id]));

        $artInfo = collect();
        if ($missing) {
            $artInfo = DB::table('articles as a')
                ->leftJoin('types as ty', 'ty.id', '=', 'a.type_id')
                ->whereIn('a.id', $missing)
                ->select('a.id', 'a.code', 'a.name', 'a.reference', 'ty.name as clase')
                ->get()
                ->keyBy('id');
        }

        usort($salidas, fn ($a, $b) => strcmp($a['clase'], $b['clase']));

        $salidasFinal = [];
        $lastClase = null;
        $blockSum = 0.0;
        $totalSalidas = 0.0;

        foreach ($salidas as $s) {
            $v = $s['values'];

            if ($lastClase !== null && $v['clase'] !== $lastClase) {
                $salidasFinal[] = $totalRow(['', '', '', '', '', '', "SubTotal Clase {$lastClase}", $n($blockSum), ''], [7]);
                $blockSum = 0.0;
            }

            $lastClase = $v['clase'];
            $blockSum += $v['total'];
            $totalSalidas += $v['total'];

            $salidasFinal[] = [
                $v['code'],
                $v['product'],
                $v['cant'],
                $v['unit'],
                $v['total'],
                $v['codDest'],
                $v['dest'],
                $v['clase'],
                $v['empleado'],
            ];
        }

        if ($lastClase !== null) {
            $salidasFinal[] = $totalRow(['', '', '', '', '', '', "SubTotal Clase {$lastClase}", $n($blockSum), ''], [7]);
            $salidasFinal[] = $totalRow(['', '', '', '', '', '', 'Total:', $n($totalSalidas), ''], [7]);
        }

        $groups = [];
        foreach ($entradas as $e) {
            $groups[$e['factura']][] = $e['values'];
        }

        $entradasFinal = [];
        $totalEntradas = 0.0;

        foreach ($groups as $factura => $lines) {
            $sum = 0.0;
            $ordenEnt = implode(', ', array_values(array_unique(array_filter(
                array_map(fn ($l) => $l['ordenEnt'], $lines)
            ))));

            foreach ($lines as $l) {
                $sum += $l['total'];

                $entradasFinal[] = [
                    $l['code'],
                    $l['product'],
                    $l['cant'],
                    $l['unit'],
                    $l['total'],
                    $ordenEnt,
                    $l['ordenComp'],
                    $l['factura'],
                    $l['clase'],
                ];
            }

            $totalEntradas += $sum;

            $label = $factura === '' ? 'Total Factura' : "Total Factura {$factura}";
            $entradasFinal[] = $totalRow(['', '', '', '', '', '', $label, $n($sum), ''], [7]);
        }

        if ($groups) {
            $entradasFinal[] = $totalRow(['', '', '', '', '', '', 'Total:', $n($totalEntradas), ''], [7]);
        }

        $movimientos = [];
        $sumIni = 0.0;
        $sumCom = 0.0;
        $sumSal = 0.0;
        $sumTot = 0.0;

        foreach ($rowIds as $id) {
            $r = $articles[$id] ?? null;

            if ($r === null) {
                $ai = $artInfo->get($id);

                $r = [
                    'code' => (string) ($ai->code ?? ''),
                    'product' => $productName((string) ($ai->name ?? ''), (string) ($ai->reference ?? '')),
                    'clase' => (string) ($ai->clase ?? ''),
                    'stock' => 0.0,
                    'ppp' => 0.0,
                    'cEnt' => 0.0,
                    'vCom' => 0.0,
                    'cSal' => 0.0,
                    'vSal' => 0.0,
                ];
            }

            $pb = $prevSnap->get($id);
            $sb = $selSnap->get($id);

            $cIni = $pb ? $pb['stock'] : 0.0;
            $uIni = $pb ? $pb['avg'] : 0.0;
            $vIni = $pb ? $pb['total'] : 0.0;

            if ($sb) {
                $cRest = $sb['stock'];
                $uFin = $sb['avg'];
                $vTot = $sb['total'];
            } else {
                $cRest = max(0, $r['stock']);
                $uFin = $cRest > 0 ? abs($r['ppp']) : 0.0;
                $vTot = $cRest * $uFin;
            }

            $vSal = $r['vSal'];
            $uSal = $r['cSal'] > 0 ? $r['vSal'] / $r['cSal'] : 0.0;
            $uCom = $r['cEnt'] > 0 ? $r['vCom'] / $r['cEnt'] : 0.0;

            $sumIni += $vIni;
            $sumCom += $r['vCom'];
            $sumSal += $vSal;
            $sumTot += $vTot;

            $movimientos[] = [
                $r['code'],
                $r['product'],
                $n($cIni),
                $n($uIni),
                $n($vIni),
                $n($r['cEnt']),
                $n($uCom),
                $n($r['vCom']),
                $n($r['cSal']),
                $n($r['cSal'] > 0 ? $uSal : 0.0),
                $n($vSal),
                $n($cRest),
                $n($uFin),
                $n($vTot),
                $id,
            ];
        }

        $movimientos[] = $totalRow(
            ['', 'TOTALES', '', '', $n($sumIni), '', '', $n($sumCom), '', '', $n($sumSal), '', '', $n($sumTot)],
            [4, 7, 10, 13]
        );

        return [
            'monthLabel' => $monthLabel,
            'movimientosHeaders' => ['CODIGO', 'PRODUCTO', 'CANT INICIAL', 'V/R UNIT.INIC', 'VALOR INICIAL', 'CANT ENTRADA', 'V/R UNIT.COMPRA', 'V/R TOTAL COMPRA', 'CANT SALIDA', 'V/R UNIT.SALIDA', 'V/R TOTAL SALIDA', 'CANTIDAD RESTANTE', 'PRECIO UNITARIO', 'VALOR TOTAL', 'Id'],
            'movimientosWidths' => [1 => 12, 2 => 42, 3 => 13, 4 => 14, 5 => 16, 6 => 13, 7 => 15, 8 => 17, 9 => 13, 10 => 15, 11 => 17, 12 => 17, 13 => 15, 14 => 17, 15 => 8],
            'movimientos' => $movimientos,
            'entradasHeaders' => ['CODIGO', 'PRODUCTO', 'CANT ENTRADA', 'V/R UNIT.ENTRADA', 'V/R TOTAL ENTRADA', 'ORDEN DE ENTRADA', 'ORDEN DE COMPRA', 'N° FACTURA', 'CLASE'],
            'entradasWidths' => [1 => 12, 2 => 42, 3 => 13, 4 => 16, 5 => 18, 6 => 17, 7 => 17, 8 => 15, 9 => 20],
            'entradas' => $entradasFinal,
            'salidasHeaders' => ['CODIGO', 'PRODUCTO', 'CANT SALIDA', 'V/R UNIT.SALIDA', 'V/R TOTAL SALIDA', 'CODIGO DESTINO', 'DESTINO', 'CLASE', 'ENPLEADO'],
            'salidasWidths' => [1 => 12, 2 => 42, 3 => 13, 4 => 16, 5 => 18, 6 => 15, 7 => 32, 8 => 20, 9 => 30],
            'salidas' => $salidasFinal,
        ];
    }
}
