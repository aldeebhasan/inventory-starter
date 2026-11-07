@php
    $short = ['sum' => 'Sum', 'avg' => 'Avg', 'min' => 'Min', 'max' => 'Max', 'count' => 'Count', 'countDistinct' => 'Distinct'][$metric['fn']] ?? $metric['fn'];
@endphp

{{-- One badge per metric on the field; hovering it offers to remove that metric. --}}
<button
    type="button"
    wire:click="removeMetric(@js($metric['as']))"
    title="Remove metric"
    class="group/metric inline-flex shrink-0 items-center gap-0.5 rounded-md bg-success-100 px-1.5 py-0.5 text-[11px] font-semibold text-success-700 hover:bg-danger-100 hover:text-danger-700 dark:bg-success-500/20 dark:text-success-300 dark:hover:bg-danger-500/20 dark:hover:text-danger-300"
>
    {{ $short }}
    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::XMark" class="hidden h-3 w-3 group-hover/metric:block" />
</button>
