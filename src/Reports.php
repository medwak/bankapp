<?php
declare(strict_types=1);

namespace Bank;

/** The classic back-office reports: trial balance, general ledger, journal listing, reconciliation. */
final class Reports
{
    /**
     * Trial balance as of a business date (inclusive), or live when null.
     * @return array{rows:array,total_debits:int,total_credits:int,balanced:bool}
     */
    public static function trialBalance(?string $asOf = null): array
    {
        $sql = 'SELECT g.code, g.name, g.type, g.normal_side,
                       COALESCE(SUM(j.debit),0) AS debits, COALESCE(SUM(j.credit),0) AS credits
                FROM gl_accounts g
                LEFT JOIN journal_entries j ON j.gl_code = g.code' . ($asOf ? ' AND j.bdate <= ?' : '') . '
                GROUP BY g.code ORDER BY g.code';
        $rows = Db::all($sql, $asOf ? [$asOf] : []);
        $td = 0;
        $tc = 0;
        foreach ($rows as &$r) {
            $net = (int) $r['debits'] - (int) $r['credits'];
            $r['dr_balance'] = max($net, 0);
            $r['cr_balance'] = max(-$net, 0);
            $r['net'] = $net;
            $td += $r['dr_balance'];
            $tc += $r['cr_balance'];
        }
        return ['rows' => $rows, 'total_debits' => $td, 'total_credits' => $tc, 'balanced' => $td === $tc];
    }

    /** Simple financial statements derived from the trial balance. */
    public static function statements(?string $asOf = null): array
    {
        $tb = self::trialBalance($asOf);
        $sum = ['ASSET' => 0, 'LIABILITY' => 0, 'EQUITY' => 0, 'INCOME' => 0, 'EXPENSE' => 0];
        foreach ($tb['rows'] as $r) {
            $sum[$r['type']] += in_array($r['type'], ['ASSET', 'EXPENSE'], true) ? $r['net'] : -$r['net'];
        }
        $profit = $sum['INCOME'] - $sum['EXPENSE'];
        return $sum + ['PROFIT' => $profit, 'CHECK' => $sum['ASSET'] - ($sum['LIABILITY'] + $sum['EQUITY'] + $profit)];
    }

    /** General ledger detail for one GL account. */
    public static function generalLedger(string $gl, ?string $from = null, ?string $to = null): array
    {
        $g = Db::one('SELECT * FROM gl_accounts WHERE code = ?', [$gl]);
        if (!$g) {
            return ['account' => null, 'rows' => [], 'opening' => 0, 'closing' => 0];
        }
        $sign = $g['normal_side'] === 'D' ? 1 : -1;
        $opening = 0;
        if ($from) {
            $opening = $sign * (int) Db::val('SELECT COALESCE(SUM(debit - credit),0) FROM journal_entries WHERE gl_code = ? AND bdate < ?', [$gl, $from]);
        }
        $sql = 'SELECT j.*, t.txn_ref, t.type_code, t.description, a.acct_no FROM journal_entries j
                JOIN transactions t ON t.id = j.txn_id
                LEFT JOIN customer_accounts a ON a.id = j.account_id
                WHERE j.gl_code = ?';
        $p = [$gl];
        if ($from) { $sql .= ' AND j.bdate >= ?'; $p[] = $from; }
        if ($to)   { $sql .= ' AND j.bdate <= ?'; $p[] = $to; }
        $rows = Db::all($sql . ' ORDER BY j.txn_id, j.line_no LIMIT 2000', $p);
        $bal = $opening;
        foreach ($rows as &$r) {
            $bal += $sign * ((int) $r['debit'] - (int) $r['credit']);
            $r['balance'] = $bal;
        }
        return ['account' => $g, 'rows' => $rows, 'opening' => $opening, 'closing' => $bal];
    }

    /** General journal for a business date, grouped by transaction. */
    public static function journal(string $bdate): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM transactions WHERE bdate = ? ORDER BY id', [$bdate]) as $t) {
            $t['reversed'] = (bool) Db::val('SELECT 1 FROM transactions WHERE reverses_txn_id = ?', [$t['id']]);
            $t['entries'] = Db::all(
                'SELECT j.*, a.acct_no FROM journal_entries j LEFT JOIN customer_accounts a ON a.id = j.account_id
                 WHERE j.txn_id = ? ORDER BY j.line_no',
                [$t['id']]
            );
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Subsidiary-ledger reconciliation - the control check every bank ran before closing the day:
     *  1. each account's cached balance equals the sum of its journal lines;
     *  2. the sum of account balances per product equals the GL control account.
     * @return string[] discrepancies (empty = reconciled)
     */
    public static function reconcile(): array
    {
        $problems = [];
        $sql = 'SELECT a.id, a.acct_no, a.ledger_balance,
                       COALESCE((SELECT SUM(credit - debit) FROM journal_entries j WHERE j.account_id = a.id AND j.gl_code = a.control_code),0) AS derived
                FROM customer_accounts a';
        foreach (Db::all($sql) as $r) {
            if ((int) $r['ledger_balance'] !== (int) $r['derived']) {
                $problems[] = "Account {$r['acct_no']}: master file " . Money::fmt((int) $r['ledger_balance']) . ' vs journal ' . Money::fmt((int) $r['derived']);
            }
        }
        // Every control account that carries customer balances (2100 demand, 2200 savings).
        foreach (Db::all('SELECT g.code, g.name FROM gl_accounts g WHERE g.code IN (SELECT DISTINCT control_code FROM customer_accounts)') as $g) {
            $sub =(int) Db::val('SELECT COALESCE(SUM(ledger_balance),0) FROM customer_accounts WHERE control_code = ?', [$g['code']]);
            $gl = (int) Db::val('SELECT COALESCE(SUM(credit - debit),0) FROM journal_entries WHERE gl_code = ?', [$g['code']]);
            if ($sub !== $gl) {
                $problems[] = "Control {$g['code']} {$g['name']}: subsidiary ledger " . Money::fmt($sub) . ' vs GL ' . Money::fmt($gl);
            }
        }
        return $problems;
    }
}
