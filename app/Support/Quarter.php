<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Immutable year+quarter identity.
 *
 * Stored as two integer columns rather than a "Q1-2026" string so range
 * queries and ordering work in SQL without parsing.
 */
final readonly class Quarter
{
    public function __construct(
        public int $year,
        public int $quarter,
    ) {
        if ($quarter < 1 || $quarter > 4) {
            throw new \InvalidArgumentException("Quarter must be 1-4, got {$quarter}.");
        }
    }

    public static function fromDate(\DateTimeInterface $date): self
    {
        $date = CarbonImmutable::instance($date);

        return new self($date->year, (int) ceil($date->month / 3));
    }

    public static function current(): self
    {
        return self::fromDate(CarbonImmutable::now());
    }

    /** Parses both "Q1-2026" (legacy) and "2026-Q1". */
    public static function parse(string $value): self
    {
        if (preg_match('/^Q(\d)-(\d{4})$/', $value, $m)) {
            return new self((int) $m[2], (int) $m[1]);
        }

        if (preg_match('/^(\d{4})-Q(\d)$/', $value, $m)) {
            return new self((int) $m[1], (int) $m[2]);
        }

        throw new \InvalidArgumentException("Unrecognised quarter: {$value}");
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, ($this->quarter - 1) * 3 + 1, 1)->startOfDay();
    }

    public function end(): CarbonImmutable
    {
        return $this->start()->addMonths(3)->subDay()->endOfDay();
    }

    public function next(): self
    {
        return $this->quarter === 4
            ? new self($this->year + 1, 1)
            : new self($this->year, $this->quarter + 1);
    }

    public function previous(): self
    {
        return $this->quarter === 1
            ? new self($this->year - 1, 4)
            : new self($this->year, $this->quarter - 1);
    }

    public function contains(\DateTimeInterface $date): bool
    {
        return self::fromDate($date)->equals($this);
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year && $this->quarter === $other->quarter;
    }

    public function label(): string
    {
        return "Q{$this->quarter} {$this->year}";
    }

    /** Sortable string form, e.g. "2026-Q3". */
    public function key(): string
    {
        return "{$this->year}-Q{$this->quarter}";
    }

    /** @return array{year: int, quarter: int} for use in query builders. */
    public function toArray(): array
    {
        return ['year' => $this->year, 'quarter' => $this->quarter];
    }

    /**
     * A run of quarters starting here.
     *
     * @return array<int, self>
     */
    public function through(int $count): array
    {
        $quarters = [$this];
        $cursor = $this;

        for ($i = 1; $i < $count; $i++) {
            $quarters[] = $cursor = $cursor->next();
        }

        return $quarters;
    }
}
