<?php
declare(strict_types=1);

namespace Bank;

use PDO;

/**
 * Thin PDO wrapper over PostgreSQL, with nested transactions (savepoints).
 *
 * Concurrency control is per-row, not global: Ledger::post() locks the specific customer
 * accounts a posting touches (SELECT ... FOR UPDATE, in a fixed ascending-id order) before it
 * reads or changes any balance. That keeps two postings against the SAME account safe while
 * postings against DIFFERENT accounts still run fully in parallel.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                Config::get('db.host', '127.0.0.1'),
                (int) Config::get('db.port', 5432),
                Config::get('db.name', 'ledgerbank')
            );
            $pdo = new PDO($dsn, (string) Config::get('db.user', 'ledgerbank'), (string) Config::get('db.pass', 'ledgerbank'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET TIME ZONE 'UTC'");
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /** Drop the connection (tests / switching database). */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$depth = 0;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $rows = self::all($sql, $params);
        return $rows[0] ?? null;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /** Run $fn atomically, nesting via savepoints when already inside a transaction. */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $level = self::$depth;
        if ($level === 0) {
            $pdo->exec('BEGIN');
        } else {
            $pdo->exec("SAVEPOINT sp{$level}");
        }
        self::$depth++;
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            self::$depth--;
            if ($level === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK');
                }
            } else {
                $pdo->exec("ROLLBACK TO SAVEPOINT sp{$level}");
                $pdo->exec("RELEASE SAVEPOINT sp{$level}");
            }
            throw $e;
        }
        self::$depth--;
        if ($level === 0) {
            $pdo->exec('COMMIT');
        } else {
            $pdo->exec("RELEASE SAVEPOINT sp{$level}");
        }
        return $result;
    }

    /** True once the schema has been installed (used by the front controller and the installer). */
    /**
     * True once the schema has been installed AND seeded (chart of accounts, first business day,
     * opening capital, first admin). Checking for a row rather than just the table's existence
     * matters: someone who imports db/schema.sql by hand (instead of letting setup.php create and
     * seed it) ends up with a real but empty gl_accounts table, and to_regclass() alone would have
     * called that "installed" - silently skipping the wizard that actually populates everything.
     */
    public static function installed(): bool
    {
        try {
            return (bool) self::val('SELECT 1 FROM gl_accounts LIMIT 1');
        } catch (\Throwable) {
            return false;
        }
    }
}