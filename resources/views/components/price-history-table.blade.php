@props([
    'prices' => [],
    'ppp' => null,
])

@php
    use Carbon\Carbon;

    $sourceLabels = [
        'ENTRY'      => 'Entrada',
        'EXIT'       => 'Salida',
        'ADJUSTMENT' => 'Ajuste',
        'MANUAL'     => 'Manual',
    ];

    $sourceColors = [
        'ENTRY'      => 'success',
        'EXIT'       => 'danger',
        'ADJUSTMENT' => 'warning',
        'MANUAL'     => 'gray',
    ];
@endphp

@if (empty($prices) || count($prices) === 0)
    <div class="flex flex-col items-center justify-center py-16 text-gray-400">
        <x-filament::icon icon="heroicon-o-currency-dollar" class="w-16 h-16 mb-4 text-gray-300" />
        <p class="text-base font-medium text-gray-500">Selecciona un art&iacute;culo</p>
        <p class="text-sm text-gray-400 mt-1">para ver su historial de precios.</p>
    </div>
@else
    <div class="space-y-5">
        @if ($ppp !== null)
            <div class="flex items-center gap-3 rounded-xl bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 px-5 py-4">
                <div class="flex items-center justify-center w-10 h-10 rounded-lg bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-scale" class="w-5 h-5" />
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Costo Promedio Ponderado</p>
                    <p class="text-xl font-bold text-gray-950 dark:text-white font-mono tabular-nums">${{ number_format($ppp, 2, '.', ',') }}</p>
                </div>
            </div>
        @endif

        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700">
                            <th class="px-5 py-3.5 text-sm font-semibold text-gray-950 dark:text-white">Fecha</th>
                            <th class="px-5 py-3.5 text-sm font-semibold text-gray-950 dark:text-white text-right">Precio Unitario</th>
                            <th class="px-5 py-3.5 text-sm font-semibold text-gray-950 dark:text-white text-center">Cantidad</th>
                            <th class="px-5 py-3.5 text-sm font-semibold text-gray-950 dark:text-white">Origen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                        @foreach ($prices as $i => $p)
                            @php
                                $isLatest = $i === 0;
                                $rowClass = $isLatest ? 'bg-primary-50/50 dark:bg-primary-900/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800/30';
                                $srcKey = strtoupper($p['source'] ?? '');
                                $srcLbl = $sourceLabels[$srcKey] ?? ($p['source'] ?? '—');
                            @endphp
                            <tr class="{{ $rowClass }} transition-colors">
                                <td class="px-5 py-3.5 text-sm whitespace-nowrap text-gray-950 dark:text-white">
                                    <div class="flex items-center gap-2">
                                        <x-filament::icon icon="heroicon-o-calendar" class="w-4 h-4 text-gray-400 shrink-0" />
                                        {{ Carbon::parse($p['date'])->format('d/m/Y') }}
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 text-sm text-right whitespace-nowrap">
                                    <span class="font-mono font-semibold tabular-nums text-gray-950 dark:text-white">${{ number_format((float) $p['price'], 2, '.', ',') }}</span>
                                    @if ($isLatest)
                                        <span class="inline-flex items-center ml-2 text-xs font-medium text-primary-600 dark:text-primary-400">
                                            <x-filament::icon icon="heroicon-o-arrow-left" class="w-3 h-3 mr-0.5" />
                                            &uacute;ltimo
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 text-sm text-center whitespace-nowrap text-gray-950 dark:text-white">
                                    <span class="tabular-nums">{{ rtrim(rtrim(number_format((float) $p['quantity'], 4, '.', ''), '0'), '.') }}</span>
                                    <span class="text-gray-400 text-xs ml-0.5">und</span>
                                </td>
                                <td class="px-5 py-3.5 text-sm whitespace-nowrap">
                                    <x-filament::badge :color="$sourceColors[$srcKey] ?? 'gray'">
                                        {{ $srcLbl }}
                                    </x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-400 dark:text-gray-500 text-center leading-relaxed">
            El valor se auto-completa con el &uacute;ltimo precio registrado.
            Puedes modificarlo manualmente si es necesario.
        </p>
    </div>
@endif
