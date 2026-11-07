<?php

namespace App\Reports;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The builder's date range presets, resolved to the `from` / `to` run-time parameters, and the period before them.
 */
final class DateRange
{
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 days',
        'last_30_days' => 'Last 30 days',
        'last_90_days' => 'Last 90 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year' => 'This year',
        'all_time' => 'All time',
        'custom' => 'Custom',
    ];

    private function __construct(public readonly ?Carbon $from, public readonly ?Carbon $to) {}

    /**
     * @param  array{preset?: string, from?: string|null, to?: string|null}  $range
     */
    public static function from(array $range, ?Carbon $now = null): self
    {
        $today = ($now ?? Carbon::now())->copy()->startOfDay();

        return match ($range['preset'] ?? 'last_30_days') {
            'today' => new self($today, $today),
            'yesterday' => new self($today->copy()->subDay(), $today->copy()->subDay()),
            'last_7_days' => new self($today->copy()->subDays(6), $today),
            'last_30_days' => new self($today->copy()->subDays(29), $today),
            'last_90_days' => new self($today->copy()->subDays(89), $today),
            'this_month' => new self($today->copy()->startOfMonth(), $today),
            'last_month' => new self($today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()),
            'this_year' => new self($today->copy()->startOfYear(), $today),
            'all_time' => new self(null, null),
            'custom' => self::custom($range),
            default => throw new InvalidArgumentException("Unknown date range preset [{$range['preset']}]."),
        };
    }

    public function isBounded(): bool
    {
        return $this->from !== null && $this->to !== null;
    }

    /** The same number of days, ending the day before this range starts. */
    public function previous(): self
    {
        if (! $this->isBounded()) {
            return $this;
        }

        $days = (int) $this->from->diffInDays($this->to);
        $to = $this->from->copy()->subDay();

        return new self($to->copy()->subDays($days), $to);
    }

    /**
     * @return array{from?: string, to?: string}
     */
    public function params(): array
    {
        return $this->isBounded() ? ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()] : [];
    }

    public function label(): string
    {
        return $this->isBounded() ? $this->from->format('M j, Y').' – '.$this->to->format('M j, Y') : 'All time';
    }

    /**
     * @param  array{from?: string|null, to?: string|null}  $range
     */
    private static function custom(array $range): self
    {
        if (blank($range['from'] ?? null) || blank($range['to'] ?? null)) {
            return new self(null, null);
        }

        [$from, $to] = [Carbon::parse($range['from'])->startOfDay(), Carbon::parse($range['to'])->startOfDay()];

        return $from->lte($to) ? new self($from, $to) : new self($to, $from);
    }
}
