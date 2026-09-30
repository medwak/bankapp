<?php
declare(strict_types=1);

namespace Bank;

/** Authentication domain logic (no HTTP here): passwords, lockout, email verification, TOTP, recovery codes. */
final class Auth
{
    private const COMMON = ['password', 'passw0rd', '123456789012', 'qwertyuiop12', 'letmein12345', 'iloveyou1234', 'administrator', 'welcome12345'];

    // ------------------------------------------------------------- passwords

    public static function hashPassword(string $pw): string
    {
        return password_hash($pw, PASSWORD_ARGON2ID);
    }

    /** @return string[] reasons the password is unacceptable (empty = fine). NIST 800-63B style: length + blocklist, no composition rules. */
    public static function passwordProblems(string $pw, string $email = '', string $name = ''): array
    {
        $min = (int) Config::get('auth.min_password_length', 12);
        $p = [];
        if (mb_strlen($pw) < $min) {
            $p[] = "Use at least {$min} characters (a passphrase works well).";
        }
        if (strlen($pw) > 200) {
            $p[] = 'Password is too long.';
        }
        $low = mb_strtolower($pw);
        $local = mb_strtolower(explode('@', $email)[0] ?? '');
        if (in_array($low, self::COMMON, true) || ($local !== '' && mb_strlen($local) >= 4 && str_contains($low, $local))) {
            $p[] = 'That password is too easy to guess.';
        }
        if (count(array_unique(mb_str_split($pw))) < 5) {
            $p[] = 'Password has too little variety.';
        }
        if (!$p && Config::get('auth.hibp_check') && self::isBreached($pw)) {
            $p[] = 'That password appears in known data breaches; choose another.';
        }
        return $p;
    }

    /** Have I Been Pwned k-anonymity range lookup. Only the first 5 hash chars leave the server. Fails open. */
    public static function isBreached(string $pw): bool
    {
        $sha = strtoupper(sha1($pw));
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'header' => "Add-Padding: true\r\nUser-Agent: LedgerBank\r\n"]]);
        $body = @file_get_contents('https://api.pwnedpasswords.com/range/' . substr($sha, 0, 5), false, $ctx);
        if ($body === false) {
            return false;
        }
        foreach (explode("\n", $body) as $line) {
            [$suffix, $count] = array_pad(explode(':', trim($line)), 2, '0');
            if (hash_equals(substr($sha, 5), strtoupper($suffix)) && (int) $count > 0) {
                return true;
            }
        }
        return false;
    }

    // ----------------------------------------------------------------- users

    public static function createUser(string $email, string $name, string $password, string $role = 'customer', bool $verified = false, int $tier = 0): int
    {
        Db::exec(
            'INSERT INTO users(email,full_name,password_hash,role,email_verified_at,kyc_tier,created_at) VALUES(?,?,?,?,?,?,?)',
            [strtolower(trim($email)), trim($name), self::hashPassword($password), $role, $verified ? time() : null, $tier, time()]
        );
        return Db::insertId();
    }

    public static function findByEmail(string $email): ?array
    {
        return Db::one('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * Self-registration. Always looks identical to the caller whether or not the email exists
     * (no account enumeration); an existing owner is told by email instead.
     * @return ?string verification token when a new account was created
     */
    public static function register(string $email, string $name, string $password): ?string
    {
        $existing = self::findByEmail($email);
        if ($existing) {
            Mailer::send($existing['email'], 'Registration attempt', 'Someone tried to register with this email address. If it was you, sign in or reset your password. Otherwise ignore this message.');
            return null;
        }
        $requireVerification = (bool) Config::get('auth.require_email_verification', true);
        $uid = self::createUser($email, $name, $password, 'customer', !$requireVerification, $requireVerification ? 0 : 1);

        if (!$requireVerification) {
            // Verification is off for now (see config/local.php) - same effect as clicking the
            // link immediately: tier 1 and a first current account, ready to sign in right away.
            Accounts::open($uid, 'DDA');
            Mailer::send($email, 'Welcome to ' . Config::get('app_name'), 'Your account is ready. Sign in whenever you like.');
            Audit::log('user.register', "user #{$uid}", $uid);
            return null;
        }

        $token = Verification::issueEmailToken($uid);
        $link = rtrim((string) Config::get('base_url', 'http://127.0.0.1:8080'), '/') . '/verify-email.php?token=' . $token;
        Mailer::send($email, 'Confirm your email address', "Welcome to " . Config::get('app_name') . ".\nConfirm your email address (link valid 24 hours):\n{$link}");
        Audit::log('user.register', "user #{$uid}", $uid);
        return $token;
    }

    /** Confirm an email link: raises the user to KYC tier 1 and opens their first current account. */
    public static function verifyEmail(string $token): bool
    {
        $uid = Verification::consumeEmailToken($token, 'verify');
        if ($uid === null) {
            return false;
        }
        Db::tx(function () use ($uid) {
            Db::exec('UPDATE users SET email_verified_at = ?, kyc_tier = GREATEST(kyc_tier, 1) WHERE id = ?', [time(), $uid]);
            if (!Db::val("SELECT 1 FROM customer_accounts WHERE user_id = ? AND product = 'DDA'", [$uid])) {
                Accounts::open($uid, 'DDA');
            }
            Audit::log('user.email_verified', "user #{$uid}", $uid);
        });
        return true;
    }

    // ----------------------------------------------------------------- login

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= password_hash('dummy-password-for-timing', PASSWORD_ARGON2ID);
    }

    /** @return array{ok:bool,error?:string,user?:array,need_2fa?:bool} */
    public static function attempt(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        $win = (int) Config::get('auth.lockout_window', 900);
        if (RateLimit::count("login:{$email}", $win) >= (int) Config::get('auth.max_failed_logins', 5)
            || RateLimit::count("loginip:{$ip}", $win) >= (int) Config::get('auth.max_failed_per_ip', 30)) {
            Db::exec('INSERT INTO login_log(email,ip,ok,at) VALUES(?,?,0,?)', [$email, $ip, time()]);
            return ['ok' => false, 'error' => 'locked'];
        }
        $user = self::findByEmail($email);
        // Always run one Argon2 verification so timing does not reveal whether the email exists.
        $good = password_verify($password, $user['password_hash'] ?? self::dummyHash());
        if (!$user || !$good || $user['status'] !== 'ACTIVE') {
            RateLimit::hit("login:{$email}");
            RateLimit::hit("loginip:{$ip}");
            Db::exec('INSERT INTO login_log(email,ip,ok,at) VALUES(?,?,0,?)', [$email, $ip, time()]);
            return ['ok' => false, 'error' => 'invalid'];
        }
        if ($user['email_verified_at'] === null) {
            return ['ok' => false, 'error' => 'unverified'];
        }
        RateLimit::clear("login:{$email}");
        Db::exec('INSERT INTO login_log(email,ip,ok,at) VALUES(?,?,1,?)', [$email, $ip, time()]);
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [self::hashPassword($password), $user['id']]);
        }
        return ['ok' => true, 'user' => $user, 'need_2fa' => (bool) $user['totp_enabled']];
    }

    // ------------------------------------------------------------------ 2FA

    /** Verify a TOTP code or a single-use recovery code. Throttled per user. */
    public static function verifySecondFactor(array $user, string $input): bool
    {
        if (!RateLimit::allow('2fa:' . $user['id'], 8, 900)) {
            return false;
        }
        $code = preg_replace('/[\s-]/', '', $input) ?? '';
        if (preg_match('/^\d{6}$/', $code) && $user['totp_secret_enc']) {
            $secret = Crypto::decrypt($user['totp_secret_enc']);
            $step = Totp::verify($secret, $code, (int) $user['totp_last_step']);
            if ($step === null) {
                return false;
            }
            // atomic replay guard: the same time-step can only ever be used once
            $n = Db::exec('UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?', [$step, $user['id'], $step]);
            if ($n === 1) {
                RateLimit::clear('2fa:' . $user['id']);
                return true;
            }
            return false;
        }
        if (preg_match('/^[0-9a-f]{10}$/i', $code)) {
            $mac = Crypto::mac('recovery|' . $user['id'] . '|' . strtolower($code));
            $n = Db::exec('UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [time(), $user['id'], $mac]);
            if ($n === 1) {
                Audit::log('auth.recovery_code_used', "user #{$user['id']}", (int) $user['id']);
                return true;
            }
        }
        return false;
    }

    /** Confirm the first code from the authenticator app, then switch 2FA on. @return ?string[] recovery codes */
    public static function enableTotp(int $userId, string $secret, string $code): ?array
    {
        $step = Totp::verify($secret, preg_replace('/\s/', '', $code) ?? '');
        if ($step === null) {
            return null;
        }
        return Db::tx(function () use ($userId, $secret, $step) {
            Db::exec(
                'UPDATE users SET totp_secret_enc = ?, totp_enabled = ?, totp_last_step = ?, security_changed_at = ? WHERE id = ?',
                [Crypto::encrypt($secret), true, $step, time(), $userId]
            );
            Db::exec('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
            $codes = [];
            for ($i = 0; $i < 8; $i++) {
                $raw = bin2hex(random_bytes(5));
                $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
                Db::exec('INSERT INTO recovery_codes(user_id, code_hash) VALUES(?,?)', [$userId, Crypto::mac("recovery|{$userId}|{$raw}")]);
            }
            Audit::log('auth.2fa_enabled', "user #{$userId}", $userId);
            return $codes;
        });
    }

    public static function changePassword(int $userId, string $current, string $new): array
    {
        $u = self::find($userId);
        if (!$u || !password_verify($current, $u['password_hash'])) {
            return ['Current password is incorrect.'];
        }
        $problems = self::passwordProblems($new, $u['email'], $u['full_name']);
        if ($problems) {
            return $problems;
        }
        Db::exec('UPDATE users SET password_hash = ?, security_changed_at = ? WHERE id = ?', [self::hashPassword($new), time(), $userId]);
        Audit::log('auth.password_changed', "user #{$userId}", $userId);
        Mailer::send($u['email'], 'Your password was changed', 'Your password was just changed. If this was not you, contact the bank immediately.');
        return [];
    }

    // --------------------------------------------------------------- devices

    /** Record the device; returns true when this is the first time we see it for the user. */
    public static function touchDevice(int $userId, string $fingerprint, string $userAgent): bool
    {
        $row = Db::one('SELECT id FROM user_devices WHERE user_id = ? AND fingerprint = ?', [$userId, $fingerprint]);
        if ($row) {
            Db::exec('UPDATE user_devices SET last_seen = ? WHERE id = ?', [time(), $row['id']]);
            return false;
        }
        Db::exec(
            'INSERT INTO user_devices(user_id,fingerprint,user_agent,first_seen,last_seen) VALUES(?,?,?,?,?)',
            [$userId, $fingerprint, mb_substr($userAgent, 0, 200), time(), time()]
        );
        return true;
    }
}