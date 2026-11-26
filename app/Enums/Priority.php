<?php

namespace App\Enums;

/** The level that drives a Complaint's SLA Targets; the Manager confirms or overrides it. */
enum Priority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /** 0 for Low up to 3 for Critical: the reverse of rank(). */
    public function severity(): int
    {
        return count(self::cases()) - 1 - $this->rank();
    }

    /**
     * SQL that turns the stored value into its severity, so `ORDER BY` follows urgency
     * rather than the alphabet. $column must be a trusted column name from code, never input.
     */
    public static function severitySql(string $column): string
    {
        $arms = array_map(fn (self $priority) => sprintf("WHEN '%s' THEN %d", $priority->value, $priority->severity()), self::cases());

        return "CASE {$column} ".implode(' ', $arms).' END';
    }

    /** 0 for the most urgent, so lists can run Critical → Low. */
    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }
}
