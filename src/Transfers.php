<?php
declare(strict_types=1);

namespace Bank;

/**
 * Customer-initiated account-to-account transfers:
 * validation -> KYC limits -> funds -> fraud engine -> (step-up | hold | block | post).
 */
final class Transfers
{
    public const POSTED = 'POSTED';
    public const STEP_UP = 'STEP_UP';
    public const HELD = 'HELD';
    public const BLOCKED = 'BLOCKED';
    public const ERROR = 'ERROR';

    /** Total already sent today (business date) by this user. */
    public static function sentToday(int $userId): int
    {
        return (int) Db::val('SELECT COALESCE(SUM(amount),0) FROM transfers WHERE user_id = ? AND bdate = ?', [$userId, Ledger::currentDay()]);
    }

    /**
     * @param array{new_device?:bool,step_up_done?:bool,idem_key?:?string} $ctx
     * @return array{status:string,message:string,ref?:string,score?:int,reasons?:string[],alert_id?:int}
     */
    public static function initiate(array $user, int $fromAccountId, string $toAcctNo, int $amount, string $memo, array $ctx = []): array
    {
        $err = static fn(string $m): array => ['status' => self::ERROR, 'message' => $m];

        $from = Accounts::find($fromAccountId);
        if (!$from || (int) $from['user_id'] !== (int) $user['id']) {
            return $err('Choose one of your own accounts.');
        }
        if ($from['status'] !== 'ACTIVE') {
            return $err('The source account is not active.');
        }
        if ((int) $user['kyc_tier'] < 1) {
            return $err('Verify your email address before making transfers.');
        }
        $toAcctNo = preg_replace('/\s+/', '', $toAcctNo) ?? '';
        if (!Accounts::validNumber($toAcctNo)) {
            return $err('That account number is not valid. Check it and try again.');
        }
        $to = Accounts::byNumber($toAcctNo);
        if (!$to || $to['status'] !== 'ACTIVE') {
            return $err('Beneficiary account could not be found.');
        }
        if ((int) $to['id'] === $fromAccountId) {
            return $err('Source and destination are the same account.');
        }
        if ($amount <= 0) {
            return $err('Enter an amount greater than zero.');
        }
        $memo = mb_substr(trim($memo), 0, 140);

        $limit = Config::dailyLimit((int) $user['kyc_tier']);
        $sent = self::sentToday((int) $user['id']);
        if ($sent + $amount > $limit) {
            return $err('This would exceed your daily transfer limit of ' . Money::fmt($limit)
                . ($user['kyc_tier'] < 3 ? '. Complete identity verification to raise it.' : '.'));
        }
        if (Ledger::available($fromAccountId) < $amount) {
            return $err('Insufficient available funds.');
        }

        $payee = (string) Db::val('SELECT u.full_name FROM users u WHERE u.id = ?', [(int) $to['user_id']]);
        $risk = Fraud::assess([
            'user_id' => (int) $user['id'], 'from_account_id' => $fromAccountId, 'to_account_id' => (int) $to['id'],
            'amount' => $amount, 'payee_name' => $payee, 'new_device' => !empty($ctx['new_device']),
        ]);

        // Large transfers always need a second factor, whatever the risk score says.
        if ($risk['decision'] === Fraud::ALLOW && $amount >= (int) Config::get('step_up_amount') * 100) {
            $risk['decision'] = Fraud::STEP_UP;
            $risk['reasons'][] = 'Amount is above the step-up threshold';
        }

        if ($risk['decision'] === Fraud::BLOCK) {
            $alert = self::openAlert($user, $from, $to, $amount, $memo, $risk, 'BLOCKED');
            Audit::log('fraud.block', "alert #{$alert} score {$risk['score']}", (int) $user['id']);
            return ['status' => self::BLOCKED, 'message' => 'This transfer was declined for security reasons. Please contact the bank.', 'alert_id' => $alert] + $risk;
        }
        if ($risk['decision'] === Fraud::HOLD) {
            $alertId = Db::tx(function () use ($user, $from, $to, $amount, $memo, $risk) {
                $id = self::openAlert($user, $from, $to, $amount, $memo, $risk, 'PENDING');
                Db::exec('INSERT INTO holds(account_id,amount,reason,alert_id,created_at) VALUES(?,?,?,?,?)', [(int) $from['id'], $amount, 'Fraud review', $id, time()]);
                return $id;
            });
            Audit::log('fraud.hold', "alert #{$alertId} score {$risk['score']}", (int) $user['id']);
            return ['status' => self::HELD, 'message' => 'For your security this transfer needs a quick review by our team. The funds are reserved and you will be notified.', 'alert_id' => $alertId] + $risk;
        }
        if ($risk['decision'] === Fraud::STEP_UP && empty($ctx['step_up_done'])) {
            return ['status' => self::STEP_UP, 'message' => 'Extra verification required.'] + $risk;
        }

        $res = self::post($user, $from, $to, $amount, $memo, $ctx['idem_key'] ?? null);
        return ['status' => self::POSTED, 'message' => 'Transfer completed.', 'ref' => $res['ref']] + $risk;
    }

    private static function post(array $user, array $from, array $to, int $amount, string $memo, ?string $idem, ?int $actor = null): array
    {
        return Db::tx(function () use ($user, $from, $to, $amount, $memo, $idem, $actor) {
            $res = Ledger::post('TRF', "Transfer {$from['acct_no']} to {$to['acct_no']}" . ($memo !== '' ? ": {$memo}" : ''), [
                ['gl' => $from['control_code'], 'account_id' => (int) $from['id'], 'dr' => $amount, 'narr' => "To {$to['acct_no']}"],
                ['gl' => $to['control_code'], 'account_id' => (int) $to['id'], 'cr' => $amount, 'narr' => "From {$from['acct_no']}"],
            ], ['user_id' => $actor ?? (int) $user['id'], 'idem_key' => $idem]);
            if (!$res['replayed']) {
                Db::exec(
                    'INSERT INTO transfers(txn_id,user_id,from_account_id,to_account_id,amount,bdate,created_at) VALUES(?,?,?,?,?,?,?)',
                    [$res['id'], (int) $user['id'], (int) $from['id'], (int) $to['id'], $amount, $res['bdate'], time()]
                );
            }
            return $res;
        });
    }

    private static function openAlert(array $user, array $from, array $to, int $amount, string $memo, array $risk, string $status): int
    {
        Db::exec(
            'INSERT INTO fraud_alerts(user_id,from_account_id,to_account_id,amount,memo,score,decision,reasons,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
            [(int) $user['id'], (int) $from['id'], (int) $to['id'], $amount, $memo, $risk['score'], $risk['decision'],
             json_encode($risk['reasons'], JSON_THROW_ON_ERROR), $status, time()]
        );
        return Db::insertId();
    }

    /** Supervisor releases a held transfer. Maker-checker: the reviewer may not be the customer. */
    public static function approveHeld(int $alertId, int $reviewerId, string $note): array
    {
        return Db::tx(function () use ($alertId, $reviewerId, $note) {
            $a = Db::one("SELECT * FROM fraud_alerts WHERE id = ? AND status = 'PENDING'", [$alertId]);
            if (!$a) {
                throw new LedgerException('Case not found or already decided.');
            }
            if ((int) $a['user_id'] === $reviewerId) {
                throw new LedgerException('You cannot review your own transfer.');
            }
            Db::exec('UPDATE holds SET released_at = ? WHERE alert_id = ? AND released_at IS NULL', [time(), $alertId]);
            $user = Auth::find((int) $a['user_id']);
            $res = self::post($user, Accounts::find((int) $a['from_account_id']), Accounts::find((int) $a['to_account_id']),
                (int) $a['amount'], (string) $a['memo'], "alert-{$alertId}", $reviewerId);
            Db::exec("UPDATE fraud_alerts SET status='APPROVED', reviewed_by=?, reviewed_at=?, note=?, txn_id=? WHERE id=?", [$reviewerId, time(), $note, $res['id'], $alertId]);
            Audit::log('fraud.approve', "alert #{$alertId} -> {$res['ref']}: {$note}", $reviewerId);
            Mailer::send($user['email'], 'Your transfer was completed', "Your reviewed transfer of " . Money::fmt((int) $a['amount']) . " has been completed (ref {$res['ref']}).");
            return $res;
        });
    }

    public static function rejectHeld(int $alertId, int $reviewerId, string $note): void
    {
        Db::tx(function () use ($alertId, $reviewerId, $note) {
            $a = Db::one("SELECT * FROM fraud_alerts WHERE id = ? AND status = 'PENDING'", [$alertId]);
            if (!$a) {
                throw new LedgerException('Case not found or already decided.');
            }
            if ((int) $a['user_id'] === $reviewerId) {
                throw new LedgerException('You cannot review your own transfer.');
            }
            Db::exec('UPDATE holds SET released_at = ? WHERE alert_id = ? AND released_at IS NULL', [time(), $alertId]);
            Db::exec("UPDATE fraud_alerts SET status='REJECTED', reviewed_by=?, reviewed_at=?, note=? WHERE id=?", [$reviewerId, time(), $note, $alertId]);
            Audit::log('fraud.reject', "alert #{$alertId}: {$note}", $reviewerId);
            $user = Auth::find((int) $a['user_id']);
            Mailer::send($user['email'], 'Your transfer was not completed', 'After review we could not complete your transfer of ' . Money::fmt((int) $a['amount']) . '. The reserved funds are available again. Please contact the bank if you need help.');
        });
    }
}
