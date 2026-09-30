<?php
declare(strict_types=1);

namespace Bank;

/**
 * Sanctions / watch-list name screening. The bundled list is a two-row demo table;
 * load a real list (OFAC, UN, local FIU) into `watchlist` and this works unchanged.
 */
final class Screening
{
    private static function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\s]/u', '', $s) ?? '';
        $tokens = preg_split('/\s+/', trim($s)) ?: [];
        sort($tokens);                          // "Doe John" == "John Doe"
        return implode(' ', $tokens);
    }

    /** @return array<int,array{name:string,list:string,similarity:float}> */
    public static function check(string $name, float $threshold = 85.0): array
    {
        $n = self::norm($name);
        if ($n === '') {
            return [];
        }
        $hits = [];
        foreach (Db::all('SELECT name, list_name FROM watchlist') as $w) {
            similar_text($n, self::norm($w['name']), $pct);
            if ($pct >= $threshold) {
                $hits[] = ['name' => $w['name'], 'list' => $w['list_name'], 'similarity' => round($pct, 1)];
            }
        }
        return $hits;
    }
}
