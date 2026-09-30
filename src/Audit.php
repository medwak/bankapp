<?php
declare(strict_types=1);

namespace Bank;

/** A plain, append-only record of who did what. Rows cannot be edited or deleted (see db/schema.sql). */
final class Audit
{
    public static function log(string $action, string $detail = '', ?int $userId = null): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $at = gmdate('Y-m-d\TH:i:s\Z');
        Db::exec(
            'INSERT INTO audit_log(at,user_id,ip,action,detail) VALUES(?,?,?,?,?)',
            [$at, $userId, $ip, $action, $detail]
        );
    }
}
