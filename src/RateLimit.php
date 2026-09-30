<?php
declare(strict_types=1);

namespace Bank;

/** Sliding-window counters kept in the database (survive restarts, shared across workers). */
final class RateLimit
{
    public static function count(string $bucket, int $windowSeconds): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND at > ?', [$bucket, time() - $windowSeconds]);
    }

    public static function hit(string $bucket): void
    {
        Db::exec('INSERT INTO rate_limits(bucket, at) VALUES(?, ?)', [$bucket, time()]);
        if (random_int(1, 50) === 1) {   // opportunistic cleanup
            Db::exec('DELETE FROM rate_limits WHERE at < ?', [time() - 86400]);
        }
    }

    public static function clear(string $bucket): void
    {
        Db::exec('DELETE FROM rate_limits WHERE bucket = ?', [$bucket]);
    }

    /** Record an attempt and report whether it is still within the allowance. */
    public static function allow(string $bucket, int $max, int $windowSeconds): bool
    {
        if (self::count($bucket, $windowSeconds) >= $max) {
            return false;
        }
        self::hit($bucket);
        return true;
    }
}
