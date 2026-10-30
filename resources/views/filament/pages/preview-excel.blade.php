<x-filament-panels::page>
    <style>
        .fi-page-preview-excel .fi-page-content { padding-top: 0 !important; }
        .fi-page-preview-excel .fi-ta-table td.fi-ta-cell { white-space: nowrap; }
        .fi-page-preview-excel .excel-scroll-wrap {
            overflow: auto;
        }
        .fi-page-preview-excel .fi-tabs-item[data-tab="entradas"].fi-active { color: rgb(22 163 74); background: rgb(220 252 231); }
        .fi-page-preview-excel .fi-tabs-item[data-tab="entradas"]:not(.fi-active):hover { background: rgb(220 252 231 / 0.5); }
        .fi-page-preview-excel .fi-tabs-item[data-tab="salidas"].fi-active { color: rgb(220 38 38); background: rgb(254 226 226); }
        .fi-page-preview-excel .fi-tabs-item[data-tab="salidas"]:not(.fi-active):hover { background: rgb(254 226 226 / 0.5); }
        .fi-page-preview-excel .fi-tabs-item[data-tab="movimientos"].fi-active { color: rgb(37 99 235); background: rgb(219 234 254); }
        .fi-page-preview-excel .fi-tabs-item[data-tab="movimientos"]:not(.fi-active):hover { background: rgb(219 234 254 / 0.5); }
        .dark .fi-page-preview-excel .fi-tabs-item[data-tab="entradas"].fi-active { color: rgb(74 222 128); background: rgb(22 163 74 / 0.15); }
        .dark .fi-page-preview-excel .fi-tabs-item[data-tab="entradas"]:not(.fi-active):hover { background: rgb(74 222 128 / 0.1); }
        .dark .fi-page-preview-excel .fi-tabs-item[data-tab="salidas"].fi-active { color: rgb(248 113 113); background: rgb(220 38 38 / 0.15); }
        .dark .fi-page-preview-excel .fi-tabs-item[data-tab="salidas"]:not(.fi-active):hover { background: rgb(248 113 113 / 0.1); }
        .dark .fi-page-preview-excel .fi-tabs-item[data-tab="movimientos"].fi-active { color: rgb(96 165 250); background: rgb(37 99 235 / 0.15); }
        .dark .fi-page-preview-excel .fi-tabs-item[data-tab="movimientos"]:not(.fi-active):hover { background: rgb(96 165 250 / 0.1); }
        @keyframes livePulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        .live-dot { animation: livePulse 2s ease-in-out infinite; }
        .fi-wi-stats-overview-stat { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .fi-wi-stats-overview-stat:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .dark .fi-wi-stats-overview-stat:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        .fi-wi-stats-overview .fi-grid-col,
        .fi-wi-stats-overview .fi-section-content > * { min-width: 0; max-width: 100%; }
        .fi-wi-stats-overview .fi-wi-stats-overview-stat,
        .fi-wi-stats-overview .fi-wi-stats-overview-stat-content {
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
        }
        .fi-wi-stats-overview .fi-wi-stats-overview-stat-label-ctn { flex-wrap: wrap; min-width: 0; }
        .fi-wi-stats-overview .fi-wi-stats-overview-stat-label { white-space: normal; overflow-wrap: anywhere; }
        .fi-wi-stats-overview .fi-wi-stats-overview-stat-value {
            font-size: clamp(0.9rem, 0.45rem + 1vw, 1.35rem);
            line-height: 1.25;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .fi-wi-stats-overview .fi-wi-stats-overview-stat-description {
            flex-wrap: wrap;
            row-gap: 0.125rem;
            white-space: normal;
        }
        .fi-wi-stats-overview .fi-wi-stats-overview-stat-description span {
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
    </style>

    <div
        class="fi-page-preview-excel flex flex-col"
        style="height: calc(100vh - 90px);"
        @if($this->isOpenMonth) wire:poll.60s="refreshData" @endif
    >
        <div class="flex items-center justify-end mb-2 gap-2">
            <span class="inline-flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400" wire:loading.delay wire:target="activeTab">
                <x-filament::loading-indicator class="h-4 w-4" />
                Cargando…
            </span>
            @if($this->isOpenMonth)
                <span class="relative flex h-2 w-2">
                    <span class="live-dot absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                </span>
                <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">EN VIVO</span>
                <span class="text-xs text-gray-400">· {{ $this->lastRefresh }}</span>
                <x-filament::button size="xs" wire:click="refreshData" color="gray" icon="heroicon-o-arrow-path">
                    Actualizar
                </x-filament::button>
            @else
                <span class="text-xs font-medium text-gray-400">Cerrado</span>
            @endif
        </div>

        @if($this->isOpenMonth && $this->draftCount > 0)
            <div class="mb-2 text-sm" style="border:1px solid rgb(245 158 11 / 0.5); background: rgb(245 158 11 / 0.12); color: rgb(245 158 11); border-radius: 0.5rem; padding: 0.5rem 1rem;">
                <strong>Mes abierto:</strong> hay {{ $this->draftCount }} {{ $this->draftCount === 1 ? 'despacho o entrada' : 'despachos o entradas' }} sin guardar (borrador) por ${{ number_format($this->draftValue, 2, '.', ',') }}.
                Todavía no cuentan en Entradas, Salidas ni en el inventario final; entran al guardarlos o al cerrar el mes.
            </div>
        @endif

        {{ $this->content }}

        <div class="excel-scroll-wrap mt-1 flex-1 min-h-0 overflow-auto shadow-lg shadow-gray-200/50 dark:shadow-none">
            {!! $this->getTable()->render() !!}
        </div>
    </div>
</x-filament-panels::page>
