<?php
declare(strict_types=1);

namespace Bank;

/** Money is integer minor units. No floats anywhere near the ledger. */
final class Money
{
    public static function fmt(int $minor): string
    {
        $neg = $minor < 0;
        $abs = abs($minor);
        return ($neg ? '-' : '') . number_format(intdiv($abs, 100)) . '.' . sprintf('%02d', $abs % 100);
    }

    /** Parse user input like "1,250.5" -> 125050. Returns null when invalid. */
    public static function parse(string $s): ?int
    {
        $s = trim(str_replace(',', '', $s));
        if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $s)) {
            return null;
        }
        [$whole, $frac] = array_pad(explode('.', $s), 2, '0');
        return (int) $whole * 100 + (int) str_pad($frac, 2, '0');
    }
}
