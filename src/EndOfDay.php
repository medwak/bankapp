<?php
declare(strict_types=1);

namespace Bank;

/**
 * The nightly batch. Everything runs in ONE database transaction: if any control check
 * fails, nothing is posted, the day stays open, and the failure is logged.
 *
 *   1. every transaction of the day balances
 *   2. interest accrual on savings (DR 5100 / CR 2300, per account)
 *   3. month-end capitalisation (DR 2300 / CR 2200)
 *   4. subsidiary ledger <-> control account reconciliation
 *   5. trial balance must balance; snapshot is stored
 *   6. day is closed, next business day opened
 */
final class EndOfDay
{
    public static function nextBusinessDay(string $bdate): string
    {
        $d = new \DateTimeImmutable($bdate);
        do {
            $d = $d->modify('+1 day');
        } while (Config::get('skip_weekends') && (int) $d->format('N') >= 6);
        return $d->format('Y-m-d');
    }

    /** @return array{ok:bool,bdate:string,next:?string,log:string[]} */
    public static function run(int $userId): array
    {
        $bdate = Ledger::currentDay();
        Db::exec("INSERT INTO eod_runs(bdate,started_at,run_by,status) VALUES(?,?,?, 'RUNNING')", [$bdate, time(), $userId]);
        $runId = Db::insertId();
        $log = [];

        try {
            $next = Db::tx(function () use ($bdate, $userId, &$log) {
                return self::steps($bdate, $userId, $log);
            });
            Db::exec("UPDATE eod_runs SET status='OK', finished_at=?, log=? WHERE id=?", [time(), implode("\n", $log), $runId]);
            Audit::log('eod.ok', "{$bdate} closed, next {$next}", $userId);
            return ['ok' => true, 'bdate' => $bdate, 'next' => $next, 'log' => $log];
        } catch (\Throwable $e) {
            $log[] = 'ABORTED: ' . $e->getMessage();
            Db::exec("UPDATE eod_runs SET status='FAILED', finished_at=?, log=? WHERE id=?", [time(), implode("\n", $log), $runId]);
            Audit::log('eod.failed', "{$bdate}: " . $e->getMessage(), $userId);
            return ['ok' => false, 'bdate' => $bdate, 'next' => null, 'log' => $log];
        }
    }

    private static function steps(string $bdate, int $userId, array &$log): string
    {
        $next = self::nextBusinessDay($bdate);
        $log[] = "Business date {$bdate}; next business date {$next}";

        // 1. every transaction balances
        $bad = Db::all('SELECT txn_id FROM journal_entries WHERE bdate = ? GROUP BY txn_id HAVING SUM(debit) <> SUM(credit)', [$bdate]);
        if ($bad) {
            throw new LedgerException('Unbalanced transactions found: ' . implode(',', array_column($bad, 'txn_id')));
        }
        $n = (int) Db::val('SELECT COUNT(*) FROM transactions WHERE bdate = ?', [$bdate]);
        $log[] = "[1] {$n} transactions, all balanced";

        $pending = (int) Db::val("SELECT COUNT(*) FROM fraud_alerts WHERE status = 'PENDING'");
        if ($pending > 0) {
            $log[] = "    note: {$pending} held transfer(s) still awaiting fraud review (funds stay on hold)";
        }

        // 2. interest accrual (covers the calendar gap to the next business day)
        $days = (new \DateTimeImmutable($bdate))->diff(new \DateTimeImmutable($next))->days;
        $rate = (int) Config::get('savings_rate_bps');
        $lines = [];
        $total = 0;
        foreach (Db::all("SELECT id, ledger_balance FROM customer_accounts WHERE product='SAV' AND status='ACTIVE' AND ledger_balance > 0 ORDER BY id") as $a) {
            $den = 10000 * 365;
            $interest = intdiv(2 * (int) $a['ledger_balance'] * $rate * $days + $den, 2 * $den);   // round half up
            if ($interest > 0) {
                $lines[] = ['gl' => '2300', 'account_id' => (int) $a['id'], 'cr' => $interest, 'narr' => "Accrual {$days}d @ {$rate}bps"];
                $total += $interest;
            }
        }
        if ($total > 0) {
            array_unshift($lines, ['gl' => '5100', 'dr' => $total, 'narr' => 'Interest expense']);
            Ledger::post('INT', "Interest accrual batch {$bdate}", $lines, ['user_id' => $userId]);
        }
        $log[] = '[2] interest accrued: ' . Money::fmt($total) . ' over ' . (count($lines) > 0 ? count($lines) - 1 : 0) . ' accounts';

        // 3. month-end capitalisation
        if (substr($next, 0, 7) !== substr($bdate, 0, 7)) {
            $cap = [];
            $capTotal = 0;
            foreach (Db::all("SELECT id FROM customer_accounts WHERE product='SAV' AND status='ACTIVE' ORDER BY id") as $a) {
                $accrued = (int) Db::val("SELECT COALESCE(SUM(credit - debit),0) FROM journal_entries WHERE gl_code='2300' AND account_id = ?", [(int) $a['id']]);
                if ($accrued > 0) {
                    $cap[] = ['gl' => '2300', 'account_id' => (int) $a['id'], 'dr' => $accrued, 'narr' => 'Release accrued interest'];
                    $cap[] = ['gl' => '2200', 'account_id' => (int) $a['id'], 'cr' => $accrued, 'narr' => 'Interest capitalised'];
                    $capTotal += $accrued;
                }
            }
            if ($capTotal > 0) {
                Ledger::post('CAP', "Month-end interest capitalisation {$bdate}", $cap, ['user_id' => $userId]);
            }
            $log[] = '[3] month-end: capitalised ' . Money::fmt($capTotal);
        } else {
            $log[] = '[3] not month-end, no capitalisation';
        }

        // 4. subsidiary ledger reconciliation
        $rec = Reports::reconcile();
        if ($rec) {
            throw new LedgerException('Reconciliation failed: ' . implode('; ', $rec));
        }
        $log[] = '[4] subsidiary ledgers reconcile to control accounts';

        // 5. trial balance
        $tb = Reports::trialBalance($bdate);
        if (!$tb['balanced']) {
            throw new LedgerException("Trial balance out of balance: DR {$tb['total_debits']} vs CR {$tb['total_credits']}");
        }
        foreach ($tb['rows'] as $r) {
            $opening = (int) Db::val('SELECT COALESCE(SUM(debit - credit),0) FROM journal_entries WHERE gl_code = ? AND bdate < ?', [$r['code'], $bdate]);
            $day = Db::one('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_entries WHERE gl_code = ? AND bdate = ?', [$r['code'], $bdate]);
            Db::exec(
                'INSERT INTO eod_trial_balance(bdate,gl_code,opening,debits,credits,closing) VALUES(?,?,?,?,?,?)',
                [$bdate, $r['code'], $opening, (int) $day['d'], (int) $day['c'], $opening + (int) $day['d'] - (int) $day['c']]
            );
        }
        $st = Reports::statements($bdate);
        if ($st['CHECK'] !== 0) {
            throw new LedgerException('Assets != Liabilities + Equity + Profit');
        }
        $log[] = '[5] trial balance agrees: DR ' . Money::fmt($tb['total_debits']) . ' = CR ' . Money::fmt($tb['total_credits']);

        // 6. close & open
        Db::exec("UPDATE business_days SET status='CLOSED', closed_at=? WHERE bdate=?", [time(), $bdate]);
        Db::exec("INSERT INTO business_days(bdate,status,opened_at) VALUES(?, 'OPEN', ?)", [$next, time()]);
        $log[] = "[6] {$bdate} closed; {$next} is now open";

        return $next;
    }
}
