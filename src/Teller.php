<?php
declare(strict_types=1);

namespace Bank;

/** Over-the-counter transactions keyed by branch staff (transaction codes DEP / WDL / FEE). */
final class Teller
{
    private static function account(int $accountId): array
    {
        $a = Accounts::find($accountId);
        if (!$a) {
            throw new LedgerException('Account not found.');
        }
        return $a;
    }

    private static function checkAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new LedgerException('Amount must be greater than zero.');
        }
        if ($amount > (int) Config::get('max_cash_txn') * 100) {
            throw new LedgerException('Amount exceeds the single-transaction cash limit.');
        }
    }

    public static function deposit(int $accountId, int $amount, int $tellerId, string $note = ''): array
    {
        self::checkAmount($amount);
        $a = self::account($accountId);
        $res = Ledger::post('DEP', 'Cash deposit ' . $a['acct_no'] . ($note !== '' ? ": {$note}" : ''), [
            ['gl' => '1010', 'dr' => $amount, 'narr' => 'Cash received'],
            ['gl' => $a['control_code'], 'account_id' => $accountId, 'cr' => $amount, 'narr' => 'Cash deposit'],
        ], ['user_id' => $tellerId]);
        Audit::log('teller.deposit', "{$res['ref']} {$a['acct_no']} " . Money::fmt($amount), $tellerId);
        return $res;
    }

    public static function withdraw(int $accountId, int $amount, int $tellerId, string $note = ''): array
    {
        self::checkAmount($amount);
        $a = self::account($accountId);
        $res = Ledger::post('WDL', 'Cash withdrawal ' . $a['acct_no'] . ($note !== '' ? ": {$note}" : ''), [
            ['gl' => $a['control_code'], 'account_id' => $accountId, 'dr' => $amount, 'narr' => 'Cash withdrawal'],
            ['gl' => '1010', 'cr' => $amount, 'narr' => 'Cash paid out'],
        ], ['user_id' => $tellerId]);
        Audit::log('teller.withdraw', "{$res['ref']} {$a['acct_no']} " . Money::fmt($amount), $tellerId);
        return $res;
    }

    public static function serviceCharge(int $accountId, int $amount, int $userId, string $note): array
    {
        if ($amount <= 0) {
            throw new LedgerException('Amount must be greater than zero.');
        }
        $a = self::account($accountId);
        $res = Ledger::post('FEE', "Service charge {$a['acct_no']}: {$note}", [
            ['gl' => $a['control_code'], 'account_id' => $accountId, 'dr' => $amount, 'narr' => 'Service charge'],
            ['gl' => '4100', 'cr' => $amount, 'narr' => 'Fee income'],
        ], ['user_id' => $userId]);
        Audit::log('teller.fee', "{$res['ref']} {$a['acct_no']} " . Money::fmt($amount), $userId);
        return $res;
    }
}
