@php
    use Filament\Support\Icons\Heroicon;

    $card = 'rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    $chip = 'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-sm font-medium ring-1';
    $input = 'block rounded-lg border-none bg-white py-1.5 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20';
    $typeIcon = fn (array $field): Heroicon => match (true) {
        $field['date'] => Heroicon::OutlinedCalendarDays,
        $field['type'] === 'bool' => Heroicon::OutlinedCheckCircle,
        in_array($field['type'], ['int', 'decimal'], true) => Heroicon::OutlinedHashtag,
        $field['type'] === 'enum' => Heroicon::OutlinedTag,
        default => Heroicon::OutlinedLanguage,
    };
@endphp

<x-filament-panels::page>
    @if ($definition === null)
        {{-- Start: pick the dataset the report is about. --}}
        <div class="space-y-8">
            <div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">What do you want to report on?</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Pick a starting dataset. You can bring in related data, like products or customers, from the field list.</p>
            </div>

            @foreach ($sections as $section => $datasets)
                <section>
                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $section }}</h3>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach ($datasets as $dataset => $label)
                            <button
                                type="button"
                                wire:click="setBase(@js($dataset))"
                                class="{{ $card }} group flex items-center gap-3 p-4 text-left transition hover:ring-2 hover:ring-primary-600"
                            >
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                                    <x-filament::icon :icon="Heroicon::OutlinedCircleStack" class="h-5 w-5" />
                                </span>
                                <span class="font-medium text-gray-950 dark:text-white">{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @else
        <div class="grid items-start gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
            {{-- Field picker --}}
            <aside class="{{ $card }} flex flex-col overflow-hidden lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)]" x-data="{ q: '' }">
                <div class="border-b border-gray-950/5 p-3 dark:border-white/10">
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-xs text-gray-500 dark:text-gray-400">Reporting on</p>
                            <p class="truncate font-semibold text-gray-950 dark:text-white">{{ $baseLabel }}</p>
                        </div>
                        @if ($canSave)
                            <button type="button" wire:click="resetBase" wire:confirm="Start over from another dataset? Your fields and filters will be cleared." class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">Change</button>
                        @endif
                    </div>
                    <input type="search" x-model="q" placeholder="Search fields…" class="{{ $input }} mt-3 w-full px-3" />
                </div>

                <div class="flex-1 space-y-1 overflow-y-auto p-2">
                    @php
                        $rowCounts = $selectedMetrics[''] ?? [];
                    @endphp
                    <div x-show="! q || 'count of rows'.includes(q.toLowerCase())" @class([
                        'group/field flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm',
                        'bg-success-50 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:ring-success-400/30' => $rowCounts !== [],
                        'hover:bg-gray-50 dark:hover:bg-white/5' => $rowCounts === [],
                    ])>
                        <x-filament::icon :icon="Heroicon::OutlinedCalculator" :class="\Illuminate\Support\Arr::toCssClasses(['h-4 w-4 shrink-0', 'text-success-600 dark:text-success-400' => $rowCounts !== [], 'text-gray-400' => $rowCounts === []])" />
                        <button type="button" wire:click="addMetric('count')" title="Add as a metric" @class(['flex-1 text-left font-medium', 'text-success-700 dark:text-success-300' => $rowCounts !== [], 'text-gray-700 dark:text-gray-200' => $rowCounts === []])>Count of rows</button>
                        @foreach ($rowCounts as $metric)
                            @include('filament.resources.report-definitions.partials.metric-badge', ['metric' => $metric])
                        @endforeach
                    </div>

                    @foreach ($groups as $groupIndex => $group)
                        <div x-data="{ open: @js($groupIndex === 0) }" wire:key="group-{{ md5($group['key']) }}">
                            <button type="button" @click="open = ! open" class="flex w-full items-center gap-1.5 rounded-lg px-2 py-1.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5">
                                <x-filament::icon :icon="Heroicon::ChevronRight" class="h-3.5 w-3.5 shrink-0 transition" x-bind:class="(open || q) && 'rotate-90'" />
                                <span class="truncate">{{ $group['label'] }}</span>
                                @if ($group['relation'])
                                    <x-filament::icon :icon="Heroicon::OutlinedLink" class="ml-auto h-3.5 w-3.5 shrink-0" />
                                @endif
                            </button>

                            <div x-show="open || q" class="pb-1">
                                @foreach ($group['fields'] as $field)
                                    @php
                                        $path = $group['relation'] ? $field['name'] : $group['alias'].'.'.$field['name'];
                                        $dimension = $field['date'] ? $path.'__day' : $path;
                                        $relation = $group['relation'];
                                        $selected = $relation === null ? ($selectedDimensions[$path] ?? null) : null;
                                        $fieldMetrics = $relation === null ? ($selectedMetrics[$path] ?? []) : [];
                                    @endphp
                                    <div
                                        @class([
                                            'group/field flex items-center gap-2 rounded-lg py-1 pl-6 pr-1',
                                            'bg-primary-50 ring-1 ring-primary-600/20 dark:bg-primary-500/10 dark:ring-primary-400/30' => $selected,
                                            'bg-success-50 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:ring-success-400/30' => ! $selected && $fieldMetrics !== [],
                                            'hover:bg-gray-50 dark:hover:bg-white/5' => ! $selected && $fieldMetrics === [],
                                        ])
                                        x-show="! q || @js(Str::lower($group['label'].' '.$field['label'])).includes(q.toLowerCase())"
                                        @if ($selected) aria-current="true" @endif
                                    >
                                        <x-filament::icon :icon="$typeIcon($field)" :class="\Illuminate\Support\Arr::toCssClasses([
                                            'h-4 w-4 shrink-0',
                                            'text-primary-600 dark:text-primary-400' => $selected,
                                            'text-success-600 dark:text-success-400' => ! $selected && $fieldMetrics !== [],
                                            'text-gray-400' => ! $selected && $fieldMetrics === [],
                                        ])" />
                                        <button
                                            type="button"
                                            @class([
                                                'min-w-0 flex-1 truncate text-left text-sm',
                                                'font-medium text-primary-700 dark:text-primary-300' => $selected,
                                                'font-medium text-success-700 dark:text-success-300' => ! $selected && $fieldMetrics !== [],
                                                'text-gray-700 dark:text-gray-200' => ! $selected && $fieldMetrics === [],
                                            ])
                                            title="{{ $field['numeric'] ? 'Add as a metric (sum)' : 'Add as a dimension' }}"
                                            @if ($field['numeric'])
                                                wire:click="addMetric('sum', @js($path), @js($relation))"
                                            @else
                                                wire:click="addDimension(@js($dimension), @js($relation))"
                                            @endif
                                        >{{ $field['label'] }}</button>
                                        @foreach ($fieldMetrics as $metric)
                                            @include('filament.resources.report-definitions.partials.metric-badge', ['metric' => $metric])
                                        @endforeach
                                        @if ($selected)
                                            {{-- Selected: a check that turns into "remove" on hover. --}}
                                            <button type="button" wire:click="removeDimension(@js($selected))" title="Remove dimension" class="shrink-0 rounded p-1 text-primary-600 hover:bg-danger-50 hover:text-danger-600 dark:text-primary-400 dark:hover:bg-danger-500/10">
                                                <x-filament::icon :icon="Heroicon::CheckCircle" class="h-4 w-4 group-hover/field:hidden" />
                                                <x-filament::icon :icon="Heroicon::XMark" class="hidden h-4 w-4 group-hover/field:block" />
                                            </button>
                                        @endif
                                        <span class="flex shrink-0 gap-0.5 opacity-0 transition group-hover/field:opacity-100">
                                            @unless ($selected)
                                                <button type="button" wire:click="addDimension(@js($dimension), @js($relation))" title="Add as dimension" class="rounded p-1 text-gray-400 hover:bg-primary-50 hover:text-primary-600 dark:hover:bg-primary-500/10">
                                                    <x-filament::icon :icon="Heroicon::OutlinedSquares2x2" class="h-3.5 w-3.5" />
                                                </button>
                                            @endunless
                                            <button type="button" wire:click="addMetric(@js($field['numeric'] ? 'sum' : 'countDistinct'), @js($path), @js($relation))" title="{{ $field['numeric'] ? 'Add as metric (sum)' : 'Add as metric (count distinct)' }}" class="rounded p-1 text-gray-400 hover:bg-primary-50 hover:text-primary-600 dark:hover:bg-primary-500/10">
                                                <x-filament::icon :icon="Heroicon::OutlinedCalculator" class="h-3.5 w-3.5" />
                                            </button>
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </aside>

            <section class="min-w-0 space-y-4">
                @unless ($canSave)
                    <div class="rounded-lg bg-primary-50 px-4 py-2 text-sm text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
                        This report is shared with you. Explore freely — changes aren't saved.
                    </div>
                @endunless

                {{-- Toolbar --}}
                <div class="{{ $card }} space-y-3 p-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <input
                            type="text"
                            wire:model.live.blur="name"
                            aria-label="Report name"
                            placeholder="Name this report"
                            title="Click to rename"
                            class="min-w-0 flex-1 rounded-lg border-none bg-transparent px-2 py-1 text-lg font-semibold text-gray-950 ring-1 ring-transparent transition hover:ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:text-white dark:hover:ring-white/20"
                        />

                        <div class="flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/5" role="group" aria-label="Visualization">
                            @foreach ($visualizations as $viz)
                                <button
                                    type="button"
                                    wire:click="setVisualization(@js($viz['type']))"
                                    @disabled(! $viz['enabled'])
                                    title="{{ $viz['label'] }}{{ $viz['hint'] ? ' — '.$viz['hint'] : '' }}"
                                    aria-pressed="{{ $visualization === $viz['type'] ? 'true' : 'false' }}"
                                    @class([
                                        'rounded-md p-1.5 transition disabled:cursor-not-allowed disabled:opacity-30',
                                        'bg-white text-primary-600 shadow-sm dark:bg-gray-800 dark:text-primary-400' => $visualization === $viz['type'],
                                        'text-gray-500 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white' => $visualization !== $viz['type'],
                                    ])
                                >
                                    <x-filament::icon :icon="$viz['icon']" class="h-5 w-5" />
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($dated)
                            <div class="flex flex-wrap items-center gap-2" x-data="{ from: @js($range['from']), to: @js($range['to']) }">
                                <label class="flex items-center gap-1.5">
                                    <x-filament::icon :icon="Heroicon::OutlinedCalendar" class="h-4 w-4 text-gray-400" />
                                    <select class="{{ $input }} pl-2 pr-8" x-on:change="$wire.setRange($event.target.value, from, to)" aria-label="Date range">
                                        @foreach ($presets as $preset => $label)
                                            <option value="{{ $preset }}" @selected($range['preset'] === $preset)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                @if ($range['preset'] === 'custom')
                                    <input type="date" x-model="from" x-on:change="$wire.setRange('custom', from, to)" class="{{ $input }} px-2" aria-label="From" />
                                    <span class="text-gray-400">–</span>
                                    <input type="date" x-model="to" x-on:change="$wire.setRange('custom', from, to)" class="{{ $input }} px-2" aria-label="To" />
                                @endif

                                @if ($range['preset'] !== 'all_time')
                                    <button type="button" wire:click="toggleCompare" @class([
                                        $chip,
                                        'bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-500/10 dark:text-primary-300' => $range['compare'],
                                        'text-gray-600 ring-gray-950/10 hover:bg-gray-50 dark:text-gray-300 dark:ring-white/20 dark:hover:bg-white/5' => ! $range['compare'],
                                    ])>
                                        <x-filament::icon :icon="Heroicon::OutlinedArrowsRightLeft" class="h-4 w-4" />
                                        Compare to previous period
                                    </button>
                                @endif
                            </div>
                            <span class="hidden h-6 w-px bg-gray-950/10 sm:block dark:bg-white/10"></span>
                        @endif

                        @foreach ($filters as $filter)
                            <span class="{{ $chip }} bg-gray-50 text-gray-700 ring-gray-950/10 dark:bg-white/5 dark:text-gray-200 dark:ring-white/20" wire:key="filter-{{ $filter['list'] }}-{{ $filter['index'] }}">
                                <x-filament::icon :icon="Heroicon::OutlinedFunnel" class="h-3.5 w-3.5 text-gray-400" />
                                {{ $filter['text'] }}
                                <button type="button" wire:click="removeFilter(@js($filter['list']), {{ $filter['index'] }})" class="text-gray-400 hover:text-danger-600" aria-label="Remove filter">
                                    <x-filament::icon :icon="Heroicon::XMark" class="h-3.5 w-3.5" />
                                </button>
                            </span>
                        @endforeach

                        <div class="relative" x-data="{ open: false, field: '', operator: '=', value: '' }" @keydown.escape="open = false" @click.outside="open = false">
                            <button type="button" @click="open = ! open" class="{{ $chip }} border border-dashed border-gray-300 text-gray-600 ring-0 hover:bg-gray-50 dark:border-white/20 dark:text-gray-300 dark:hover:bg-white/5">
                                <x-filament::icon :icon="Heroicon::Plus" class="h-4 w-4" /> Filter
                            </button>
                            <form x-show="open" x-transition x-cloak
                                  @submit.prevent="if (field) { $wire.addFilter(field, operator, value); open = false; field = ''; value = ''; operator = '=' }"
                                  class="{{ $card }} absolute left-0 z-20 mt-2 w-80 space-y-2 p-3">
                                <select x-model="field" class="{{ $input }} w-full px-2" aria-label="Field" required>
                                    <option value="">Choose a field…</option>
                                    @foreach ($filterFields as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div class="flex gap-2">
                                    <select x-model="operator" class="{{ $input }} w-28 px-2" aria-label="Operator">
                                        @foreach ($operators as $operator)
                                            <option value="{{ $operator }}">{{ $operator }}</option>
                                        @endforeach
                                    </select>
                                    <input x-model="value" x-show="! ['null', 'not null'].includes(operator)" placeholder="Value (comma separated for lists)" class="{{ $input }} min-w-0 flex-1 px-2" aria-label="Value" />
                                </div>
                                <div class="flex justify-end">
                                    <x-filament::button type="submit" size="sm">Apply</x-filament::button>
                                </div>
                            </form>
                        </div>

                        <label class="ml-auto flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                            Show
                            <select class="{{ $input }} pl-2 pr-8" x-on:change="$wire.setLimit($event.target.value ? parseInt($event.target.value) : null)" aria-label="Row limit">
                                <option value="" @selected($limit === null)>All rows</option>
                                @foreach ([5, 10, 25, 50, 100] as $top)
                                    <option value="{{ $top }}" @selected($limit === $top)>Top {{ $top }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </div>

                {{-- Dimensions and metrics --}}
                <div class="{{ $card }} grid gap-4 p-3 md:grid-cols-2">
                    @foreach (['Dimensions' => $dimensions, 'Metrics' => $metrics] as $zone => $items)
                        <div>
                            <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <span @class(['h-2 w-2 rounded-full', 'bg-success-500' => $zone === 'Metrics', 'bg-primary-500' => $zone === 'Dimensions'])></span>
                                {{ $zone }}
                            </p>
                            <div class="flex flex-wrap gap-2">
                                @forelse ($items as $item)
                                    @php($isMetric = $zone === 'Metrics')
                                    @php($key = $isMetric ? $item['as'] : $item['field'])
                                    <div wire:key="chip-{{ md5($key) }}" x-data="{ edit: false, label: @js($item['customLabel'] ?? ''), format: @js($item['format']) }" @click.outside="edit = false" @class([
                                        $chip, 'relative',
                                        'bg-success-50 text-success-800 ring-success-600/20 dark:bg-success-500/10 dark:text-success-200 dark:ring-success-400/30' => $isMetric,
                                        'bg-primary-50 text-primary-800 ring-primary-600/20 dark:bg-primary-500/10 dark:text-primary-200 dark:ring-primary-400/30' => ! $isMetric,
                                    ])>
                                        <button type="button" @click="edit = ! edit" class="max-w-[14rem] truncate" title="Rename or format">{{ $item['label'] }}</button>

                                        @if (! $isMetric && $item['date'])
                                            <select wire:change="setBucket(@js($item['field']), $event.target.value || null)" class="rounded border-none bg-white/70 py-0 pl-1.5 pr-6 text-xs dark:bg-white/10" aria-label="Group dates by">
                                                <option value="" @selected($item['bucket'] === null)>Exact</option>
                                                @foreach (['day', 'week', 'month', 'year'] as $bucket)
                                                    <option value="{{ $bucket }}" @selected($item['bucket'] === $bucket)>{{ ucfirst($bucket) }}</option>
                                                @endforeach
                                            </select>
                                        @endif

                                        @if ($isMetric && $item['field'] !== null)
                                            <select wire:change="setMetricFunction(@js($item['as']), $event.target.value)" class="rounded border-none bg-white/70 py-0 pl-1.5 pr-6 text-xs dark:bg-white/10" aria-label="Aggregate">
                                                @foreach ($aggregateLabels as $fn => $fnLabel)
                                                    @continue($fn === 'count')
                                                    <option value="{{ $fn }}" @selected($item['fn'] === $fn)>{{ $fnLabel }}</option>
                                                @endforeach
                                            </select>
                                        @endif

                                        <button type="button" wire:click="{{ $isMetric ? 'removeMetric' : 'removeDimension' }}(@js($key))" class="opacity-60 hover:text-danger-600 hover:opacity-100" aria-label="Remove">
                                            <x-filament::icon :icon="Heroicon::XMark" class="h-3.5 w-3.5" />
                                        </button>

                                        <form x-show="edit" x-transition x-cloak @submit.prevent="$wire.updateColumn(@js($key), label, format); edit = false" class="{{ $card }} absolute left-0 top-full z-20 mt-2 w-64 space-y-2 p-3 font-normal text-gray-950 dark:text-white">
                                            <input x-model="label" placeholder="{{ $item['label'] }}" class="{{ $input }} w-full px-2" aria-label="Label" />
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Leave empty to name it after the {{ $isMetric ? 'metric' : 'field' }}.</p>
                                            <select x-model="format" class="{{ $input }} w-full px-2" aria-label="Format">
                                                @foreach ($formats as $format)
                                                    <option value="{{ $format }}">{{ ucfirst($format) }}</option>
                                                @endforeach
                                            </select>
                                            <div class="flex justify-end"><x-filament::button type="submit" size="sm">Apply</x-filament::button></div>
                                        </form>
                                    </div>
                                @empty
                                    <p class="text-sm text-gray-400">
                                        {{ $zone === 'Dimensions' ? 'Click a field to break the report down by it.' : 'Click a number to total it, or "Count of rows".' }}
                                    </p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Canvas --}}
                <div class="{{ $card }} relative overflow-hidden">
                    {{-- Hidden by class: Livewire's own hiding CSS skips the combined `.delay.flex` modifier. --}}
                    <div wire:loading.delay.flex class="absolute inset-0 z-10 hidden items-center justify-center bg-white/60 dark:bg-gray-900/60">
                        <x-filament::loading-indicator class="h-8 w-8 text-primary-600" />
                    </div>

                    @if ($preview['range'])
                        <div class="flex flex-wrap items-center gap-x-2 border-b border-gray-950/5 px-4 py-2 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <span class="font-medium text-gray-700 dark:text-gray-200">{{ $preview['range'] }}</span>
                            @if ($preview['compareRange'])
                                <span>compared to {{ $preview['compareRange'] }}</span>
                            @endif
                        </div>
                    @endif

                    @if ($preview['error'])
                        <div class="flex items-start gap-3 p-6 text-sm text-danger-700 dark:text-danger-400">
                            <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="h-5 w-5 shrink-0" />
                            <div>
                                <p class="font-semibold">This report needs editing</p>
                                <p class="mt-1">{{ $preview['error'] }}</p>
                            </div>
                        </div>
                    @else
                        @if ($preview['kpis'] !== [])
                            <div class="grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-4">
                                @foreach ($preview['kpis'] as $kpi)
                                    <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $kpi['label'] }}</p>
                                        <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $kpi['value'] }}</p>
                                        @if ($kpi['change'] !== null)
                                            <p @class(['mt-1 inline-flex items-center gap-1 text-sm font-medium', 'text-success-600 dark:text-success-400' => $kpi['change'] >= 0, 'text-danger-600 dark:text-danger-400' => $kpi['change'] < 0])>
                                                <x-filament::icon :icon="$kpi['change'] >= 0 ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown" class="h-4 w-4" />
                                                {{ $kpi['change'] >= 0 ? '+' : '' }}{{ $kpi['change'] }}%
                                            </p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div @class(['border-b border-gray-950/5 p-4 dark:border-white/10', 'hidden' => $preview['chart'] === null])>
                            <div wire:ignore x-data="reportChart" class="relative h-80">
                                <canvas x-ref="canvas" role="img" aria-label="Report chart"></canvas>
                            </div>
                        </div>

                        <div class="max-h-[34rem] overflow-auto">
                            <table class="w-full text-sm">
                                <thead class="sticky top-0 z-[1] bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        @foreach ($preview['columns'] as $index => $column)
                                            @php($sortKey = $columnFields[$index] ?? null)
                                            <th scope="col" @class(['whitespace-nowrap px-4 py-2.5 font-semibold text-gray-700 dark:text-gray-200', 'text-right' => $column['numeric'], 'text-left' => ! $column['numeric']])>
                                                @if ($sortKey)
                                                    <button type="button" wire:click="sortBy(@js($sortKey))" class="inline-flex items-center gap-1 hover:text-primary-600">
                                                        {{ $column['label'] }}
                                                        @if (($order['column'] ?? null) === $sortKey)
                                                            <x-filament::icon :icon="($order['direction'] ?? 'asc') === 'asc' ? Heroicon::ChevronUp : Heroicon::ChevronDown" class="h-3.5 w-3.5" />
                                                        @endif
                                                    </button>
                                                @else
                                                    {{ $column['label'] }}
                                                @endif
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-950/5 dark:divide-white/5">
                                    @forelse ($preview['rows'] as $row)
                                        <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                            @foreach ($row as $index => $cell)
                                                <td @class(['whitespace-nowrap px-4 py-2 text-gray-700 dark:text-gray-300', 'text-right tabular-nums' => $preview['columns'][$index]['numeric']])>{{ $cell === '' ? '—' : $cell }}</td>
                                            @endforeach
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ max(1, count($preview['columns'])) }}" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">
                                                @if ($preview['columns'] === [])
                                                    Pick fields on the left to build the report.
                                                @elseif ($preview['range'])
                                                    <p>No data between {{ $preview['range'] }}.</p>
                                                    <button type="button" wire:click="setRange('all_time')" class="mt-2 font-medium text-primary-600 hover:underline dark:text-primary-400">Show all time</button>
                                                @else
                                                    No rows match.
                                                @endif
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                                @if ($preview['totals'])
                                    <tfoot class="sticky bottom-0 bg-gray-50 font-semibold dark:bg-gray-800">
                                        <tr>
                                            @foreach ($preview['totals'] as $index => $total)
                                                <td @class(['whitespace-nowrap px-4 py-2.5 text-gray-950 dark:text-white', 'text-right tabular-nums' => $preview['columns'][$index]['numeric']])>{{ $total }}</td>
                                            @endforeach
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>

                        @if ($preview['truncated'])
                            <p class="border-t border-gray-950/5 px-4 py-2 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                                Showing the first {{ number_format(\App\Reports\ReportPreview::ROW_LIMIT) }} rows. Export the report for all of them.
                            </p>
                        @endif
                    @endif
                </div>
            </section>
        </div>
    @endif
</x-filament-panels::page>
