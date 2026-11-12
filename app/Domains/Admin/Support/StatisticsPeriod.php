<?php

namespace App\Domains\Admin\Support;

use App\Domains\Payment\Support\SettlementWeek;
use Carbon\CarbonImmutable;

/**
 * The period a Statistics page reads (T01–T05): today, this or last settlement week
 * (Saturday to Friday, Cairo — PAY-11), this or last calendar month. Each knows the period
 * before it of the same length, for the "compared with" line, and whether it is still
 * running ("so far").
 */
final readonly class StatisticsPeriod
{
    public const array KEYS = ['today', 'this_week', 'last_week', 'this_month', 'last_month'];

    private function __construct(
        public string $key,
        public CarbonImmutable $from,   // Cairo, inclusive
        public CarbonImmutable $until,  // Cairo, exclusive
    ) {}

    public static function of(string $key, ?CarbonImmutable $now = null): self
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(SettlementWeek::TIMEZONE);
        $week = SettlementWeek::startOf($now);

        return match ($key) {
            'today' => new self($key, $now->startOfDay(), $now->startOfDay()->addDay()),
            'last_week' => new self($key, $week->subWeek(), $week),
            'this_month' => new self($key, $now->startOfMonth(), $now->startOfMonth()->addMonth()),
            'last_month' => new self($key, $now->startOfMonth()->subMonth(), $now->startOfMonth()),
            default => new self('this_week', $week, $week->addWeek()),
        };
    }

    /** The period just before this one, the same length. */
    public function previous(): self
    {
        return match ($this->key) {
            'this_month', 'last_month' => new self($this->key, $this->from->subMonth(), $this->from),
            default => new self($this->key, $this->from->subDays((int) round($this->from->diffInDays($this->until))), $this->from),
        };
    }

    public function isRunning(): bool
    {
        return CarbonImmutable::now()->lt($this->until);
    }

    /** For created_at columns, stored in UTC. */
    public function fromUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    public function untilUtc(): CarbonImmutable
    {
        return $this->until->utc();
    }

    /** For DATE columns that hold a Cairo day (trip_date). */
    public function firstDay(): string
    {
        return $this->from->toDateString();
    }

    public function lastDay(): string
    {
        return $this->until->subDay()->toDateString();
    }
}
