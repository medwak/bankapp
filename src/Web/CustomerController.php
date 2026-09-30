<?php
declare(strict_types=1);

namespace Bank\Web;

use Bank\{Accounts, Audit, Auth, Config, Db, Kyc, Ledger, Money, RateLimit, Totp, Transfers, Verification};

final class CustomerController
{
    private static function customer(): array
    {
        $u = Http::requireLogin();
        if ($u['role'] !== 'customer') {
            Http::redirect('staff.php');
        }
        return $u;
    }

    public static function dashboard(): void
    {
        $u = self::customer();
        $accounts = Accounts::forUser((int) $u['id']);
        $pending = Db::all("SELECT * FROM fraud_alerts WHERE user_id = ? AND status = 'PENDING' ORDER BY id DESC", [$u['id']]);
        $recent = Db::all(
            "SELECT j.bdate, j.debit, j.credit, t.txn_ref, t.description, a.acct_no
             FROM journal_entries j JOIN transactions t ON t.id = j.txn_id JOIN customer_accounts a ON a.id = j.account_id
             WHERE a.user_id = ? AND j.gl_code = a.control_code ORDER BY j.id DESC LIMIT 8",
            [$u['id']]
        );
        Http::render('dashboard', [
            'title' => 'My accounts', 'user' => $u, 'accounts' => $accounts, 'pending' => $pending, 'recent' => $recent,
            'limit' => Config::dailyLimit((int) $u['kyc_tier']), 'sent' => Transfers::sentToday((int) $u['id']),
        ]);
    }

    public static function account(string $id): void
    {
        $u = self::customer();
        $a = Accounts::find((int) $id);
        if (!$a || (int) $a['user_id'] !== (int) $u['id']) {
            Http::fail(404, 'Account not found.');
        }
        $from = Http::get('from') ?: null;
        $to = Http::get('to') ?: null;
        foreach ([$from, $to] as $d) {
            if ($d !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                Http::fail(400, 'Invalid date.');
            }
        }
        $st = Accounts::statement((int) $a['id'], $from, $to);
        Http::render('account', [
            'title' => 'Statement', 'user' => $u, 'acct' => $a, 'st' => $st, 'from' => $from, 'to' => $to,
            'available' => Ledger::available((int) $a['id']),
        ]);
    }

    // -------------------------------------------------------------- transfers

    private static function transferForm(array $u, array $old = []): void
    {
        $_SESSION['idem'] = $_SESSION['idem'] ?? bin2hex(random_bytes(16));
        Http::render('transfer', [
            'title' => 'Transfer money', 'user' => $u, 'accounts' => Accounts::forUser((int) $u['id']), 'old' => $old,
            'limit' => Config::dailyLimit((int) $u['kyc_tier']), 'sent' => Transfers::sentToday((int) $u['id']),
        ]);
    }

    public static function transferShow(): void
    {
        self::transferForm(self::customer());
    }

    public static function transferSubmit(): void
    {
        $u = self::customer();
        Http::requirePostCsrf();
        $amount = Money::parse(Http::post('amount'));
        $old = ['from' => Http::post('from'), 'to' => Http::post('to'), 'amount' => Http::post('amount'), 'memo' => Http::post('memo')];
        if ($amount === null || $amount <= 0) {
            Http::flash('error', 'Enter a valid amount, for example 1500.00.');
            self::transferForm($u, $old);
            return;
        }
        $idem = $_SESSION['idem'] ?? bin2hex(random_bytes(16));
        $r = Transfers::initiate($u, (int) $old['from'], $old['to'], $amount, $old['memo'], [
            'new_device' => !empty($_SESSION['new_device']), 'idem_key' => 'web-' . $u['id'] . '-' . $idem,
        ]);
        self::handleResult($u, $r, $old, $amount);
    }

    private static function handleResult(array $u, array $r, array $old, int $amount): void
    {
        switch ($r['status']) {
            case Transfers::POSTED:
                unset($_SESSION['idem'], $_SESSION['pending_transfer']);
                Http::flash('ok', "Transfer of " . Money::fmt($amount) . " completed. Reference {$r['ref']}.");
                Http::redirect('dashboard.php');
            case Transfers::STEP_UP:
                $method = $u['totp_enabled'] ? 'totp' : 'email';
                $_SESSION['pending_transfer'] = $old + ['amount_minor' => $amount, 'exp' => time() + 300, 'method' => $method, 'tries' => 0];
                if ($method === 'email') {
                    $code = Verification::issueOtp($u, 'transfer');
                    if (Config::get('dev_mode')) {
                        Http::flash('info', "DEV MODE - your code: {$code}");
                    }
                }
                Http::redirect('transfer-verify.php');
            case Transfers::HELD:
                unset($_SESSION['idem'], $_SESSION['pending_transfer']);
                Http::flash('info', $r['message']);
                Http::redirect('dashboard.php');
            case Transfers::BLOCKED:
                unset($_SESSION['idem'], $_SESSION['pending_transfer']);
                Http::flash('error', $r['message']);
                Http::redirect('dashboard.php');
            default:
                Http::flash('error', $r['message']);
                self::transferForm($u, $old);
        }
    }

    public static function verifyShow(): void
    {
        $u = self::customer();
        $p = $_SESSION['pending_transfer'] ?? null;
        if (!$p || $p['exp'] < time()) {
            unset($_SESSION['pending_transfer']);
            Http::flash('error', 'That verification request expired. Please start the transfer again.');
            Http::redirect('transfer.php');
        }
        $to = Accounts::byNumber(preg_replace('/\s+/', '', $p['to']) ?? '');
        Http::render('transfer_verify', [
            'title' => 'Confirm transfer', 'user' => $u, 'p' => $p, 'payee' => $to ? Accounts::maskedHolder((int) $to['id']) : '?',
        ]);
    }

    public static function verifySubmit(): void
    {
        $u = self::customer();
        Http::requirePostCsrf();
        $p = $_SESSION['pending_transfer'] ?? null;
        if (!$p || $p['exp'] < time()) {
            unset($_SESSION['pending_transfer']);
            Http::flash('error', 'That verification request expired. Please start the transfer again.');
            Http::redirect('transfer.php');
        }
        $code = Http::post('code');
        $good = $p['method'] === 'totp'
            ? Auth::verifySecondFactor($u, $code)
            : Verification::checkOtp((int) $u['id'], 'transfer', $code);
        if (!$good) {
            $_SESSION['pending_transfer']['tries']++;
            if ($_SESSION['pending_transfer']['tries'] >= 5) {
                unset($_SESSION['pending_transfer']);
                Audit::log('transfer.stepup_failed', 'too many wrong codes', (int) $u['id']);
                Http::flash('error', 'Too many incorrect codes. The transfer was cancelled.');
                Http::redirect('transfer.php');
            }
            Http::flash('error', 'That code is not correct.');
            Http::redirect('transfer-verify.php');
        }
        $idem = $_SESSION['idem'] ?? bin2hex(random_bytes(16));
        $old = ['from' => $p['from'], 'to' => $p['to'], 'amount' => $p['amount'], 'memo' => $p['memo']];
        $r = Transfers::initiate($u, (int) $p['from'], $p['to'], (int) $p['amount_minor'], $p['memo'], [
            'new_device' => !empty($_SESSION['new_device']), 'step_up_done' => true, 'idem_key' => 'web-' . $u['id'] . '-' . $idem,
        ]);
        unset($_SESSION['pending_transfer']);
        self::handleResult($u, $r, $old, (int) $p['amount_minor']);
    }

    /** Confirmation-of-payee: masked name so the sender can spot a mistyped number. */
    public static function lookup(): void
    {
        $u = self::customer();
        if (!RateLimit::allow('lookup:' . $u['id'], 30, 3600)) {
            Http::json(['error' => 'Too many lookups. Try again later.'], 429);
        }
        $no = preg_replace('/\s+/', '', Http::get('acct')) ?? '';
        if (!Accounts::validNumber($no)) {
            Http::json(['error' => 'Not a valid account number.']);
        }
        $a = Accounts::byNumber($no);
        if (!$a || $a['status'] !== 'ACTIVE') {
            Http::json(['error' => 'Account not found.']);
        }
        Http::json(['name' => Accounts::maskedHolder((int) $a['id'])]);
    }

    // --------------------------------------------------------------- security

    public static function security(): void
    {
        $u = Http::requireLogin();
        $setup = null;
        if (!$u['totp_enabled']) {
            $_SESSION['totp_setup'] ??= Totp::generateSecret();
            $setup = ['secret' => $_SESSION['totp_setup'], 'uri' => Totp::uri($_SESSION['totp_setup'], $u['email'], (string) Config::get('app_name'))];
        }
        $recovery = $_SESSION['recovery_codes'] ?? null;
        unset($_SESSION['recovery_codes']);
        Http::render('security', [
            'title' => 'Security', 'user' => $u, 'setup' => $setup, 'recovery' => $recovery,
            'devices' => Db::all('SELECT * FROM user_devices WHERE user_id = ? ORDER BY last_seen DESC', [$u['id']]),
            'remaining' => (int) Db::val('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$u['id']]),
        ]);
    }

    public static function securityPost(): void
    {
        $u = Http::requireLogin();
        Http::requirePostCsrf();
        $action = Http::post('action');
        if ($action === 'enable2fa' && !$u['totp_enabled'] && isset($_SESSION['totp_setup'])) {
            if (!RateLimit::allow('2fa-setup:' . $u['id'], 10, 900)) {
                Http::flash('error', 'Too many attempts. Wait a few minutes.');
            } else {
                $codes = Auth::enableTotp((int) $u['id'], $_SESSION['totp_setup'], Http::post('code'));
                if ($codes === null) {
                    Http::flash('error', 'That code did not match. Make sure your phone clock is automatic and try the next code.');
                } else {
                    unset($_SESSION['totp_setup']);
                    $_SESSION['recovery_codes'] = $codes;
                    Http::flash('ok', 'Two-factor authentication is now on.');
                }
            }
        } elseif ($action === 'password') {
            if (Http::post('new') !== Http::post('new2')) {
                Http::flash('error', 'The new passwords do not match.');
            } else {
                $problems = Auth::changePassword((int) $u['id'], Http::post('current'), Http::post('new'));
                if ($problems) {
                    foreach ($problems as $p) {
                        Http::flash('error', $p);
                    }
                } else {
                    Http::flash('ok', 'Password changed.');
                }
            }
        }
        Http::redirect('security.php');
    }

    // ------------------------------------------------------------------- KYC

    public static function kyc(): void
    {
        $u = self::customer();
        Http::render('kyc', [
            'title' => 'Verify your identity', 'user' => $u, 'p' => Db::one('SELECT * FROM kyc_profiles WHERE user_id = ?', [$u['id']]), 'old' => [],
        ]);
    }

    public static function kycPost(): void
    {
        $u = self::customer();
        Http::requirePostCsrf();
        $r = Kyc::submit((int) $u['id'], $_POST);
        if (!$r['ok']) {
            foreach ($r['errors'] as $e) {
                Http::flash('error', $e);
            }
            Http::render('kyc', ['title' => 'Verify your identity', 'user' => $u, 'p' => null, 'old' => $_POST]);
            return;
        }
        Http::flash('ok', 'Thanks - your details were submitted and will be reviewed by our team.');
        Http::redirect('kyc.php');
    }
}
