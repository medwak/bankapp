<?php
declare(strict_types=1);

namespace Bank\Web;

use Bank\{Auth, Config, Mailer};

/** Request/session/CSRF/view plumbing. Deliberately small; no framework. */
final class Http
{
    public static function boot(): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' && Config::get('trust_proxy_proto', false);

        header('Content-Security-Policy: default-src \'self\'; img-src \'self\' data:; style-src \'self\'; script-src \'self\'; frame-ancestors \'none\'; form-action \'self\'; base-uri \'none\'');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');   // no-referrer would make browsers send "Origin: null" on form posts
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cache-Control: no-store');
        if ($https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        $dir = Config::get('storage') . '/sessions';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        session_save_path($dir);
        session_name('BANKSID');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) Config::get('session.absolute_seconds', 28800));
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => $https, 'samesite' => 'Strict']);
        session_start();
    }

    // ------------------------------------------------------------------ input

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';   // do not trust X-Forwarded-For unless you sit behind a known proxy
    }

    public static function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public static function get(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    // ------------------------------------------------------------------- CSRF

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    /** Every state-changing request must carry the session's token (and, if present, a same-origin Origin header). */
    public static function requirePostCsrf(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
            self::fail(403, 'Cross-origin request refused.');
        }
        $sent = self::post('_csrf');
        if ($sent === '' || !hash_equals(self::csrf(), $sent)) {
            self::fail(419, 'Your session expired or the form was tampered with. Go back, refresh, and try again.');
        }
    }

    // ----------------------------------------------------------------- output

    public static function redirect(string $to): never
    {
        header('Location: ' . $to, true, 303);
        exit;
    }

    public static function fail(int $status, string $message): never
    {
        http_response_code($status);
        self::render('error', ['title' => "Error {$status}", 'message' => $message]);
        exit;
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public static function flash(string $type, string $msg): void
    {
        $_SESSION['flash'][] = [$type, $msg];
    }

    public static function render(string $view, array $vars = []): void
    {
        $vars['user'] ??= self::user();
        $vars['flashes'] = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        $vars['title'] ??= (string) Config::get('app_name');
        extract($vars, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/views/' . $view . '.php';
        $content = ob_get_clean();
        require dirname(__DIR__, 2) . '/views/layout.php';
    }

    // ----------------------------------------------------------- session/user

    public static function user(): ?array
    {
        $uid = $_SESSION['uid'] ?? null;
        if (!$uid) {
            return null;
        }
        $now = time();
        $ua = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($now - ($_SESSION['last_seen'] ?? 0) > (int) Config::get('session.idle_seconds', 600)
            || $now - ($_SESSION['login_at'] ?? 0) > (int) Config::get('session.absolute_seconds', 28800)
            || ($_SESSION['ua'] ?? '') !== $ua) {
            self::destroySession();
            return null;
        }
        try {
            $u = Auth::find((int) $uid);
        } catch (\Throwable) {
            // A session cookie can outlive the database it points at - e.g. the schema was just
            // reset. Every page (even the error page itself) calls user() to build the nav bar, so
            // a query failure here must not become a fatal error; treat it as "not logged in".
            return null;
        }
        if (!$u || $u['status'] !== 'ACTIVE') {
            self::destroySession();
            return null;
        }
        $_SESSION['last_seen'] = $now;
        return $u;
    }

    public static function signIn(array $user): void
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $flash = $_SESSION['flash'] ?? [];
        session_regenerate_id(true);                // defeats session fixation
        $_SESSION = [
            'uid' => (int) $user['id'], 'login_at' => time(), 'last_seen' => time(),
            'ua' => hash('sha256', $ua), 'csrf' => bin2hex(random_bytes(32)), 'flash' => $flash,
        ];
        $fingerprint = hash('sha256', $ua . '|' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        $_SESSION['new_device'] = Auth::touchDevice((int) $user['id'], $fingerprint, $ua);
        if ($_SESSION['new_device']) {
            Mailer::send($user['email'], 'New sign-in to your account', 'We noticed a sign-in from a new device (' . mb_substr($ua, 0, 80) . ') from IP ' . self::ip() . '. If this was not you, change your password and contact the bank.');
        }
        \Bank\Audit::log('auth.login', "user #{$user['id']}", (int) $user['id']);
    }

    public static function destroySession(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** @param string[]|null $roles */
    public static function requireLogin(?array $roles = null): array
    {
        $u = self::user();
        if (!$u) {
            self::redirect('login.php');
        }
        if ($roles !== null && !in_array($u['role'], $roles, true)) {
            self::fail(403, 'You do not have permission to view this page.');
        }
        return $u;
    }

    /** Staff must have 2FA on before they can touch anything - while auth.require_staff_2fa is on. */
    public static function requireStaff(bool $supervisorOnly = false): array
    {
        $u = self::requireLogin($supervisorOnly ? ['supervisor'] : ['teller', 'supervisor']);
        if (Config::get('auth.require_staff_2fa', true) && !$u['totp_enabled']) {
            self::flash('error', 'Staff accounts must enable two-factor authentication before continuing.');
            self::redirect('security.php');
        }
        return $u;
    }
}