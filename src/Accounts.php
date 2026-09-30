<?php
declare(strict_types=1);

namespace Bank;

/** Subsidiary ledger: customer account master. */
final class Accounts
{
    private const BRANCH = '001';
    private const PRODUCTS = ['DDA' => '2100', 'SAV' => '2200'];

    public static function luhnCheckDigit(string $digits): int
    {
        $sum = 0;
        $double = true;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }
        return (10 - ($sum % 10)) % 10;
    }

    public static function validNumber(string $no): bool
    {
        return (bool) preg_match('/^\d{10}$/', $no)
            && self::luhnCheckDigit(substr($no, 0, 9)) === (int) $no[9];
    }

    /** @return array{id:int,acct_no:string} */
    public static function open(int $userId, string $product = 'DDA'): array
    {
        if (!isset(self::PRODUCTS[$product])) {
            throw new LedgerException('Unknown product.');
        }
        return Db::tx(function () use ($userId, $product) {
            do {
                $base = self::BRANCH . sprintf('%06d', random_int(1, 999999));
                $no = $base . self::luhnCheckDigit($base);
            } while (Db::val('SELECT 1 FROM customer_accounts WHERE acct_no = ?', [$no]));
            Db::exec(
                'INSERT INTO customer_accounts(user_id,acct_no,product,control_code,opened_bdate) VALUES(?,?,?,?,?)',
                [$userId, $no, $product, self::PRODUCTS[$product], Ledger::currentDay()]
            );
            $id = Db::insertId();
            Audit::log('account.open', "{$product} {$no} for user #{$userId}", $userId);
            return ['id' => $id, 'acct_no' => $no];
        });
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM customer_accounts WHERE id = ?', [$id]);
    }

    public static function byNumber(string $no): ?array
    {
        return Db::one('SELECT * FROM customer_accounts WHERE acct_no = ?', [$no]);
    }

    public static function forUser(int $userId): array
    {
        $rows = Db::all('SELECT * FROM customer_accounts WHERE user_id = ? ORDER BY id', [$userId]);
        foreach ($rows as &$r) {
            $r['available'] = Ledger::available((int) $r['id']);
        }
        return $rows;
    }

    /** "Confirmation of payee": show enough of the name to catch typos, not enough to harvest. */
    public static function maskedHolder(int $accountId): string
    {
        $name = (string) Db::val('SELECT u.full_name FROM customer_accounts a JOIN users u ON u.id = a.user_id WHERE a.id = ?', [$accountId]);
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return implode(' ', array_map(
            static fn(string $p) => mb_substr($p, 0, 1) . str_repeat('*', max(2, mb_strlen($p) - 1)),
            $parts
        ));
    }

    /** Passbook-style statement with running balance. */
    public static function statement(int $accountId, ?string $from = null, ?string $to = null): array
    {
        $acct = self::find($accountId);
        if (!$acct) {
            return ['opening' => 0, 'rows' => [], 'closing' => 0];
        }
        $ctl = $acct['control_code'];
        $opening = 0;
        if ($from !== null) {
            $opening = (int) Db::val(
                'SELECT COALESCE(SUM(credit - debit),0) FROM journal_entries WHERE account_id = ? AND gl_code = ? AND bdate < ?',
                [$accountId, $ctl, $from]
            );
        }
        $sql = 'SELECT j.*, t.txn_ref, t.type_code, t.description FROM journal_entries j
                JOIN transactions t ON t.id = j.txn_id
                WHERE j.account_id = ? AND j.gl_code = ?';
        $p = [$accountId, $ctl];
        if ($from !== null) { $sql .= ' AND j.bdate >= ?'; $p[] = $from; }
        if ($to !== null)   { $sql .= ' AND j.bdate <= ?'; $p[] = $to; }
        $rows = Db::all($sql . ' ORDER BY j.txn_id, j.line_no', $p);
        $bal = $opening;
        foreach ($rows as &$r) {
            $bal += (int) $r['credit'] - (int) $r['debit'];
            $r['balance'] = $bal;
        }
        return ['opening' => $opening, 'rows' => $rows, 'closing' => $bal];
    }
}
