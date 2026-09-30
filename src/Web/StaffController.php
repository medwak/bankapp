<?php
declare(strict_types=1);

namespace Bank\Web;

use Bank\{Accounts, Audit, Auth, Db, EndOfDay, Installer, Kyc, Ledger, LedgerException, Money, Reports, Teller, Transfers};

final class StaffController
{
    /** Run a staff action, turning business-rule failures into a flash message. */
    private static function attempt(callable $fn, string $okMessage, string $back): never
    {
        try {
            $fn();
            Http::flash('ok', $okMessage);
        } catch (LedgerException $e) {
            Http::flash('error', $e->getMessage());
        }
        Http::redirect($back);
    }

    public static function home(): void
    {
        $u = Http::requireStaff();
        Http::render('staff/home', [
            'title' => 'Staff console', 'user' => $u, 'bdate' => Ledger::currentDay(),
            'alerts' => (int) Db::val("SELECT COUNT(*) FROM fraud_alerts WHERE status = 'PENDING'"),
            'kyc' => (int) Db::val("SELECT COUNT(*) FROM kyc_profiles WHERE status = 'PENDING'"),
            'txns' => (int) Db::val('SELECT COUNT(*) FROM transactions WHERE bdate = ?', [Ledger::currentDay()]),
            'recon' => Reports::reconcile(),
            'tb' => Reports::trialBalance(),
        ]);
    }

    // ---------------------------------------------------------------- teller

    public static function teller(): void
    {
        $u = Http::requireStaff();
        $acct = null;
        $no = preg_replace('/\s+/', '', Http::get('acct')) ?? '';
        if ($no !== '') {
            $acct = Accounts::byNumber($no);
            if ($acct) {
                $acct['holder'] = Db::val('SELECT full_name FROM users WHERE id = ?', [$acct['user_id']]);
                $acct['available'] = Ledger::available((int) $acct['id']);
            } else {
                Http::flash('error', 'Account not found.');
            }
        }
        Http::render('staff/teller', ['title' => 'Teller', 'user' => $u, 'acct' => $acct, 'no' => $no]);
    }

    public static function tellerPost(): void
    {
        $u = Http::requireStaff();
        Http::requirePostCsrf();
        $action = Http::post('action');
        $accId = (int) Http::post('account_id');
        $back = 'staff-teller.php?acct=' . urlencode(Http::post('acct_no'));
        $amount = Money::parse(Http::post('amount'));

        if ($action === 'open') {
            $cust = Auth::findByEmail(Http::post('email'));
            self::attempt(function () use ($cust, $u) {
                if (!$cust || $cust['role'] !== 'customer' || (int) $cust['kyc_tier'] < 1) {
                    throw new LedgerException('Customer not found or email not verified.');
                }
                $a = Accounts::open((int) $cust['id'], Http::post('product') === 'SAV' ? 'SAV' : 'DDA');
                Audit::log('teller.open_account', $a['acct_no'], (int) $u['id']);
            }, 'Account opened.', 'staff-teller.php');
        }
        if ($amount === null || $amount <= 0) {
            Http::flash('error', 'Enter a valid amount.');
            Http::redirect($back);
        }
        match ($action) {
            'deposit'  => self::attempt(fn() => Teller::deposit($accId, $amount, (int) $u['id'], Http::post('note')), 'Deposit posted.', $back),
            'withdraw' => self::attempt(fn() => Teller::withdraw($accId, $amount, (int) $u['id'], Http::post('note')), 'Withdrawal posted.', $back),
            'fee'      => $u['role'] === 'supervisor'
                ? self::attempt(fn() => Teller::serviceCharge($accId, $amount, (int) $u['id'], Http::post('note') ?: 'Service charge'), 'Service charge posted.', $back)
                : Http::fail(403, 'Supervisor only.'),
            default    => Http::fail(400, 'Unknown action.'),
        };
    }

    // --------------------------------------------------------------- reports

    public static function journal(): void
    {
        $u = Http::requireStaff();
        $d = Http::get('date') ?: Ledger::currentDay();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            Http::fail(400, 'Invalid date.');
        }
        Http::render('staff/journal', ['title' => 'General journal', 'user' => $u, 'date' => $d, 'txns' => Reports::journal($d)]);
    }

    public static function ledger(): void
    {
        $u = Http::requireStaff();
        $gl = Http::get('gl', '1010');
        $from = Http::get('from') ?: null;
        $to = Http::get('to') ?: null;
        foreach ([$from, $to] as $d) {
            if ($d !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                Http::fail(400, 'Invalid date.');
            }
        }
        Http::render('staff/ledger', [
            'title' => 'General ledger', 'user' => $u, 'gl' => $gl, 'from' => $from, 'to' => $to,
            'coa' => Db::all('SELECT code, name FROM gl_accounts ORDER BY code'), 'ledger' => Reports::generalLedger($gl, $from, $to),
        ]);
    }

    public static function trial(): void
    {
        $u = Http::requireStaff();
        $asof = Http::get('asof') ?: null;
        if ($asof !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asof)) {
            Http::fail(400, 'Invalid date.');
        }
        Http::render('staff/trial', [
            'title' => 'Trial balance', 'user' => $u, 'asof' => $asof,
            'tb' => Reports::trialBalance($asof), 'st' => Reports::statements($asof),
            'subs' => Db::all('SELECT product, COUNT(*) n, SUM(ledger_balance) bal FROM customer_accounts GROUP BY product'),
        ]);
    }

    public static function accounts(): void
    {
        $u = Http::requireStaff();
        $q = trim(Http::get('q'));
        $rows = Db::all(
            "SELECT a.*, u.full_name, u.email FROM customer_accounts a JOIN users u ON u.id = a.user_id
             WHERE (? = '' OR a.acct_no LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?) ORDER BY a.id DESC LIMIT 200",
            [$q, "%{$q}%", "%{$q}%", "%{$q}%"]
        );
        Http::render('staff/accounts', ['title' => 'Customer accounts', 'user' => $u, 'rows' => $rows, 'q' => $q]);
    }

    // ---------------------------------------------------------- end of day

    public static function eod(): void
    {
        $u = Http::requireStaff(true);
        Http::render('staff/eod', [
            'title' => 'End of day', 'user' => $u, 'bdate' => Ledger::currentDay(), 'next' => EndOfDay::nextBusinessDay(Ledger::currentDay()),
            'runs' => Db::all('SELECT * FROM eod_runs ORDER BY id DESC LIMIT 10'),
            'pending' => (int) Db::val("SELECT COUNT(*) FROM fraud_alerts WHERE status = 'PENDING'"),
            'result' => $_SESSION['eod_result'] ?? null,
        ]);
        unset($_SESSION['eod_result']);
    }

    public static function eodPost(): void
    {
        $u = Http::requireStaff(true);
        Http::requirePostCsrf();
        if (Http::post('confirm') !== Ledger::currentDay()) {
            Http::flash('error', 'Type the current business date to confirm.');
            Http::redirect('staff-eod.php');
        }
        $_SESSION['eod_result'] = EndOfDay::run((int) $u['id']);
        Http::redirect('staff-eod.php');
    }

    // ---------------------------------------------------------------- fraud

    public static function fraud(): void
    {
        $u = Http::requireStaff();
        $rows = Db::all(
            "SELECT f.*, u.full_name, u.email, fa.acct_no AS from_no, ta.acct_no AS to_no, tu.full_name AS to_name
             FROM fraud_alerts f JOIN users u ON u.id = f.user_id
             JOIN customer_accounts fa ON fa.id = f.from_account_id JOIN customer_accounts ta ON ta.id = f.to_account_id
             JOIN users tu ON tu.id = ta.user_id
             ORDER BY (f.status = 'PENDING') DESC, f.id DESC LIMIT 100"
        );
        foreach ($rows as &$r) {
            $r['reasons'] = json_decode($r['reasons'], true) ?: [];
        }
        Http::render('staff/fraud', ['title' => 'Fraud review', 'user' => $u, 'rows' => $rows]);
    }

    public static function fraudDecide(string $id): void
    {
        $u = Http::requireStaff(true);
        Http::requirePostCsrf();
        $note = trim(Http::post('note'));
        if (mb_strlen($note) < 5) {
            Http::flash('error', 'Write a short note explaining your decision.');
            Http::redirect('staff-fraud.php');
        }
        $approve = Http::post('decision') === 'approve';
        self::attempt(
            fn() => $approve ? Transfers::approveHeld((int) $id, (int) $u['id'], $note) : Transfers::rejectHeld((int) $id, (int) $u['id'], $note),
            $approve ? 'Transfer approved and posted.' : 'Transfer rejected; funds released.',
            'staff-fraud.php'
        );
    }

    // ------------------------------------------------------------------ KYC

    public static function kyc(): void
    {
        $u = Http::requireStaff();
        $rows = Db::all(
            "SELECT k.*, u.email, u.kyc_tier FROM kyc_profiles k JOIN users u ON u.id = k.user_id
             ORDER BY (k.status = 'PENDING') DESC, k.submitted_at DESC LIMIT 100"
        );
        foreach ($rows as &$r) {
            $r['hits'] = json_decode((string) $r['screening'], true) ?: [];
        }
        Http::render('staff/kyc', ['title' => 'KYC review', 'user' => $u, 'rows' => $rows]);
    }

    public static function kycDecide(string $userId): void
    {
        $u = Http::requireStaff(true);
        Http::requirePostCsrf();
        $approve = Http::post('decision') === 'approve';
        self::attempt(
            fn() => Kyc::review((int) $userId, (int) $u['id'], $approve, (int) Http::post('tier', '2'), trim(Http::post('note')), Http::post('override') === '1'),
            $approve ? 'KYC approved.' : 'KYC rejected.',
            'staff-kyc.php'
        );
    }

    public static function reverse(): void
    {
        $u = Http::requireStaff(true);
        Http::requirePostCsrf();
        $reason = trim(Http::post('reason'));
        if (mb_strlen($reason) < 5) {
            Http::flash('error', 'A reason is required.');
            Http::redirect('staff-journal.php');
        }
        self::attempt(fn() => Ledger::reverse((int) Http::post('txn_id'), $reason, (int) $u['id']), 'Reversal posted to the current business day.', 'staff-journal.php');
    }

    public static function audit(): void
    {
        $u = Http::requireStaff(true);
        Http::render('staff/audit', ['title' => 'Audit trail', 'user' => $u, 'rows' => Db::all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 200')]);
    }

    // ---------------------------------------------------------------- staff

    /** Supervisor-only staff management, replacing `php bin/create-staff.php`. */
    public static function users(): void
    {
        $u = Http::requireStaff(true);
        Http::render('staff/users', [
            'title' => 'Staff accounts', 'user' => $u,
            'rows' => Db::all("SELECT id, email, full_name, role, status, totp_enabled, created_at FROM users WHERE role IN ('teller','supervisor') ORDER BY id DESC"),
            'created' => $_SESSION['staff_created'] ?? null,
        ]);
        unset($_SESSION['staff_created']);
    }

    public static function usersPost(): void
    {
        $u = Http::requireStaff(true);
        Http::requirePostCsrf();
        $email = trim(Http::post('email'));
        $name = trim(Http::post('full_name'));
        $role = Http::post('role');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            Http::flash('error', 'Enter a valid email address.');
            Http::redirect('staff-users.php');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            Http::flash('error', 'Enter a full name.');
            Http::redirect('staff-users.php');
        }
        if (!in_array($role, ['teller', 'supervisor'], true)) {
            Http::flash('error', 'Choose a role.');
            Http::redirect('staff-users.php');
        }
        if (Auth::findByEmail($email)) {
            Http::flash('error', 'That email already has an account.');
            Http::redirect('staff-users.php');
        }

        $pw = Installer::randomPassword();
        $id = Auth::createUser($email, $name, $pw, $role, true, 3);
        Audit::log('staff.create', "user #{$id} {$role}", (int) $u['id']);
        $_SESSION['staff_created'] = ['email' => $email, 'password' => $pw];
        Http::flash('ok', 'Staff account created. The password is shown once below - two-factor authentication is required at first sign-in.');
        Http::redirect('staff-users.php');
    }
}
