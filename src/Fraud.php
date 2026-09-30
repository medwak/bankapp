<?php
declare(strict_types=1);

namespace Bank;

/**
 * Real-time, explainable fraud scoring for outgoing transfers.
 * Each rule adds weighted points; the total maps to an action:
 *
 *   < step_up_at            ALLOW    - post immediately
 *   >= step_up_at           STEP_UP  - second factor (TOTP or emailed code) required
 *   >= hold_at              HOLD     - not posted; funds held; supervisor must review
 *   >= block_at             BLOCK    - refused and logged as a case
 *
 * Rules are transparent on purpose (auditors and customers can be told why), and each has a
 * weight in config/config.php. A statistical / ML model can be added as one more rule.
 */
final class Fraud
{
    public const ALLOW = 'ALLOW';
    public const STEP_UP = 'STEP_UP';
    public const HOLD = 'HOLD';
    public const BLOCK = 'BLOCK';

    /**
     * @param array{user_id:int,from_account_id:int,to_account_id:int,amount:int,payee_name:string,new_device?:bool,now?:int} $c
     * @return array{score:int,decision:string,reasons:string[]}
     */
    public static function assess(array $c): array
    {
        $w = Config::get('fraud.weights');
        $now = $c['now'] ?? time();
        $uid = $c['user_id'];
        $amount = $c['amount'];
        $score = 0;
        $reasons = [];
        $add = static function (string $rule, string $why) use (&$score, &$reasons, $w): void {
            $score += (int) $w[$rule];
            $reasons[] = "{$why} (+{$w[$rule]})";
        };
        // "Soft" signals (new payee / new device / odd hour) are ignored for small everyday payments,
        // otherwise every first coffee-money transfer from a new phone would be challenged.
        $material = $amount >= (int) Config::get('fraud.low_value', 1000) * 100;

        // 1. amount far above this customer's normal outgoing size
        $hist = Db::one('SELECT AVG(amount) a, COUNT(*) n FROM transfers WHERE user_id = ? AND created_at > ?', [$uid, $now - 30 * 86400]);
        if ((int) $hist['n'] >= 5 && $amount > 3 * (float) $hist['a']) {
            $add('amount_spike', 'Amount is more than 3x the customer\'s 30-day average');
        }

        // 2. velocity
        $lastHour = (int) Db::val('SELECT COUNT(*) FROM transfers WHERE user_id = ? AND created_at > ?', [$uid, $now - 3600]);
        if ($lastHour >= 4) {
            $add('velocity', "{$lastHour} transfers already in the last hour");
        }

        // 3. first payment to this account
        if ($material && !Db::val('SELECT 1 FROM transfers WHERE user_id = ? AND to_account_id = ? LIMIT 1', [$uid, $c['to_account_id']])) {
            $add('new_payee', 'First payment to this beneficiary');
        }

        // 4. structuring: just under the reporting threshold, or many smaller ones adding up to it
        $threshold = (int) Config::get('reporting_threshold') * 100;
        if ($amount >= (int) ($threshold * 0.9) && $amount < $threshold) {
            $add('structuring', 'Amount sits just below the reporting threshold');
        } else {
            $day = Db::one('SELECT COALESCE(SUM(amount),0) s, COUNT(*) n FROM transfers WHERE user_id = ? AND created_at > ?', [$uid, $now - 86400]);
            if ((int) $day['n'] >= 2 && (int) $day['s'] + $amount >= $threshold && $amount < $threshold) {
                $add('near_threshold', 'Several transfers in 24h together reach the reporting threshold');
            }
        }

        // 5. unusual hour (local time)
        $hour = (int) date('G', $now);
        if ($material && $hour < 5) {
            $add('night', 'Initiated between 00:00 and 05:00');
        }

        // 6. new device
        if ($material && !empty($c['new_device'])) {
            $add('new_device', 'Session is from a device not seen before');
        }

        // 7. credentials or 2FA changed in the last 24h and the amount is material
        $u = Auth::find($uid);
        if ($u && $u['security_changed_at'] !== null && $u['security_changed_at'] > $now - 86400
            && $amount >= (int) Config::get('step_up_amount') * 100) {
            $add('recent_change', 'Password or 2FA changed within the last 24 hours');
        }

        // 8. failed sign-ins recently
        if ($u) {
            $fails = (int) Db::val('SELECT COUNT(*) FROM login_log WHERE email = ? AND ok = 0 AND at > ?', [$u['email'], $now - 86400]);
            if ($fails >= 3) {
                $add('failed_logins', "{$fails} failed sign-ins in the last 24 hours");
            }
        }

        // 9. money-mule pattern: beneficiary is collecting from many different people
        $senders = (int) Db::val('SELECT COUNT(DISTINCT user_id) FROM transfers WHERE to_account_id = ? AND created_at > ?', [$c['to_account_id'], $now - 86400]);
        if ($senders >= 4) {
            $add('mule_pattern', "Beneficiary received from {$senders} different customers in 24h");
        }

        // 10. sweeping almost the whole balance
        $avail = Ledger::available($c['from_account_id']);
        if ($avail > 0 && $amount >= (int) ($avail * 0.9) && $amount >= (int) Config::get('step_up_amount') * 100) {
            $add('draining', 'Transfer drains 90%+ of the available balance');
        }

        $forceHold = false;
        $hits = Screening::check($c['payee_name']);
        if ($hits) {
            $add('watchlist', 'Beneficiary name matches watch-list entry "' . $hits[0]['name'] . '" (' . $hits[0]['similarity'] . '%)');
            $forceHold = true;
        }

        $score = min(100, $score);
        $f = Config::get('fraud');
        $decision = self::ALLOW;
        if ($score >= $f['block_at']) {
            $decision = self::BLOCK;
        } elseif ($score >= $f['hold_at'] || $forceHold) {
            $decision = self::HOLD;
        } elseif ($score >= $f['step_up_at']) {
            $decision = self::STEP_UP;
        }
        return ['score' => $score, 'decision' => $decision, 'reasons' => $reasons];
    }
}
