<?php
declare(strict_types=1);

namespace Bank;

/**
 * The general journal. Every monetary event in the bank is one call to Ledger::post():
 * a balanced set of debit/credit lines against GL accounts, with subsidiary-ledger
 * (customer account) references on control accounts. Rows are never updated or deleted;
 * mistakes are fixed with Ledger::reverse().
 */
final class Ledger
{
    public static function currentDay(): string
    {
        $d = Db::val("SELECT bdate FROM business_days WHERE status = 'OPEN'");
        if ($d === null) {
            throw new LedgerException('No open business day.');
        }
        return (string) $d;
    }

    public static function available(int $accountId): int
    {
        $bal = (int) Db::val('SELECT ledger_balance FROM customer_accounts WHERE id = ?', [$accountId]);
        $held = (int) Db::val('SELECT COALESCE(SUM(amount),0) FROM holds WHERE account_id = ? AND released_at IS NULL', [$accountId]);
        return $bal - $held;
    }

    /**
     * @param array<int,array{gl:string,dr?:int,cr?:int,account_id?:?int,narr?:string}> $lines
     * @param array{user_id?:?int,idem_key?:?string,reverses?:?int,skip_funds_check?:bool} $opts
     * @return array{id:int,ref:string,bdate:string,replayed:bool}
     */
    public static function post(string $type, string $description, array $lines, array $opts = []): array
    {
        return Db::tx(function () use ($type, $description, $lines, $opts) {
            $userId = $opts['user_id'] ?? null;
            $idem = $opts['idem_key'] ?? null;

            if ($idem !== null) {
                $ex = Db::one('SELECT id, txn_ref, bdate FROM transactions WHERE idem_key = ?', [$idem]);
                if ($ex) {
                    return ['id' => (int) $ex['id'], 'ref' => $ex['txn_ref'], 'bdate' => $ex['bdate'], 'replayed' => true];
                }
            }
            if (!Db::val('SELECT 1 FROM txn_types WHERE code = ?', [$type])) {
                throw new LedgerException("Unknown transaction code {$type}.");
            }

            $bdate = self::currentDay();
            [$entries, $netByAccount] = self::validate($lines);

            // Lock every customer account this entry touches, in a fixed (ascending id) order, before
            // reading or changing any balance. That prevents two concurrent postings on the SAME account
            // from racing (e.g. both approving an overdraft neither alone would allow), while postings
            // against DIFFERENT accounts still run fully in parallel. Locking in a stable order also
            // avoids deadlocks when two transfers touch the same two accounts in opposite directions.
            if ($netByAccount) {
                $ids = array_keys($netByAccount);
                sort($ids);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                Db::all("SELECT id FROM customer_accounts WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE", $ids);
            }

            // Funds check: only debits against a customer's own control account can overdraw it.
            if (empty($opts['skip_funds_check'])) {
                foreach ($netByAccount as $accId => $net) {
                    if ($net >= 0) {
                        continue;
                    }
                    $acct = Db::one('SELECT allow_overdraft FROM customer_accounts WHERE id = ?', [$accId]);
                    if (!$acct['allow_overdraft'] && self::available($accId) + $net < 0) {
                        throw new LedgerException('Insufficient available funds.');
                    }
                }
            }

            $postedAt = gmdate('Y-m-d\TH:i:s\Z');
            $reverses = $opts['reverses'] ?? null;

            // txn_ref is left NULL on insert (a UNIQUE index allows any number of NULLs) and
            // filled in once the id is known, so concurrent postings never collide on a shared
            // placeholder value.
            Db::exec(
                'INSERT INTO transactions(idem_key,type_code,bdate,posted_at,user_id,description,reverses_txn_id)
                 VALUES(?,?,?,?,?,?,?)',
                [$idem, $type, $bdate, $postedAt, $userId, $description, $reverses]
            );
            $id = Db::insertId();
            $ref = sprintf('T%s-%06d', str_replace('-', '', $bdate), $id);
            Db::exec('UPDATE transactions SET txn_ref = ? WHERE id = ?', [$ref, $id]);

            foreach ($entries as $n => $e) {
                Db::exec(
                    'INSERT INTO journal_entries(txn_id,line_no,bdate,gl_code,account_id,debit,credit,narrative) VALUES(?,?,?,?,?,?,?,?)',
                    [$id, $e['line'], $bdate, $e['gl'], $e['account_id'], $e['dr'], $e['cr'], $e['narr']]
                );
            }
            // Subsidiary ledger master file: cached running balance of each customer account.
            foreach ($netByAccount as $accId => $net) {
                Db::exec('UPDATE customer_accounts SET ledger_balance = ledger_balance + ? WHERE id = ?', [$net, $accId]);
            }

            return ['id' => $id, 'ref' => $ref, 'bdate' => $bdate, 'replayed' => false];
        });
    }

    /** @return array{0:array,1:array<int,int>} normalised entries and net effect per customer account */
    private static function validate(array $lines): array
    {
        if (count($lines) < 2) {
            throw new LedgerException('A journal entry needs at least two lines.');
        }
        $dr = 0;
        $cr = 0;
        $entries = [];
        $net = [];
        foreach (array_values($lines) as $i => $l) {
            $n = $i + 1;
            $d = (int) ($l['dr'] ?? 0);
            $c = (int) ($l['cr'] ?? 0);
            if ($d < 0 || $c < 0 || ($d > 0) === ($c > 0)) {
                throw new LedgerException("Line {$n}: exactly one of debit or credit must be positive.");
            }
            $gl = (string) ($l['gl'] ?? '');
            $g = Db::one('SELECT code, is_control, subledger_product, active FROM gl_accounts WHERE code = ?', [$gl]);
            if (!$g || !$g['active']) {
                throw new LedgerException("Line {$n}: GL account {$gl} does not exist or is inactive.");
            }
            $accId = isset($l['account_id']) ? (int) $l['account_id'] : null;
            if ($g['is_control'] && $accId === null) {
                throw new LedgerException("Line {$n}: control account {$gl} requires a subsidiary account.");
            }
            if (!$g['is_control'] && $accId !== null) {
                throw new LedgerException("Line {$n}: GL account {$gl} is not a control account.");
            }
            if ($accId !== null) {
                $a = Db::one('SELECT id, product, control_code, status FROM customer_accounts WHERE id = ?', [$accId]);
                if (!$a) {
                    throw new LedgerException("Line {$n}: customer account not found.");
                }
                if ($a['status'] !== 'ACTIVE') {
                    throw new LedgerException("Line {$n}: customer account is {$a['status']}.");
                }
                if ($a['product'] !== $g['subledger_product']) {
                    throw new LedgerException("Line {$n}: account product {$a['product']} does not belong to {$gl}.");
                }
                if ($a['control_code'] === $gl) {
                    $net[$accId] = ($net[$accId] ?? 0) + $c - $d;
                }
            }
            $dr += $d;
            $cr += $c;
            $entries[] = ['line' => $n, 'gl' => $gl, 'account_id' => $accId, 'dr' => $d, 'cr' => $c, 'narr' => (string) ($l['narr'] ?? '')];
        }
        if ($dr !== $cr) {
            throw new LedgerException("Entry does not balance: debits {$dr} / credits {$cr}.");
        }
        return [$entries, $net];
    }

    /** Post an exact mirror image of a transaction into the current business day. */
    public static function reverse(int $txnId, string $reason, int $userId): array
    {
        return Db::tx(function () use ($txnId, $reason, $userId) {
            $t = Db::one('SELECT * FROM transactions WHERE id = ?', [$txnId]);
            if (!$t) {
                throw new LedgerException('Transaction not found.');
            }
            if ($t['reverses_txn_id'] !== null) {
                throw new LedgerException('A reversal cannot itself be reversed.');
            }
            if (Db::val('SELECT 1 FROM transactions WHERE reverses_txn_id = ?', [$txnId])) {
                throw new LedgerException('Transaction has already been reversed.');
            }
            $lines = [];
            foreach (Db::all('SELECT * FROM journal_entries WHERE txn_id = ? ORDER BY line_no', [$txnId]) as $e) {
                $lines[] = [
                    'gl' => $e['gl_code'], 'account_id' => $e['account_id'] === null ? null : (int) $e['account_id'],
                    'dr' => (int) $e['credit'], 'cr' => (int) $e['debit'], 'narr' => 'REV ' . $e['narrative'],
                ];
            }
            $res = self::post('REV', "Reversal of {$t['txn_ref']}: {$reason}", $lines, [
                'user_id' => $userId, 'reverses' => $txnId, 'skip_funds_check' => true,
            ]);
            Audit::log('ledger.reverse', "{$t['txn_ref']} -> {$res['ref']}: {$reason}", $userId);
            return $res;
        });
    }
}
