<?php
declare(strict_types=1);

namespace Bank\Web;

use Bank\{Auth, Config, RateLimit};

final class AuthController
{
    public static function home(): void
    {
        $u = Http::user();
        Http::redirect($u ? ($u['role'] === 'customer' ? 'dashboard.php' : 'staff.php') : 'login.php');
    }

    public static function registerForm(): void
    {
        Http::render('register', ['title' => 'Open an account', 'old' => []]);
    }

    public static function register(): void
    {
        Http::requirePostCsrf();
        $email = trim(Http::post('email'));
        $name = trim(Http::post('full_name'));
        $pw = Http::post('password');
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $errors[] = 'Enter a valid email address.';
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors[] = 'Enter your full name.';
        }
        if ($pw !== Http::post('password2')) {
            $errors[] = 'The two passwords do not match.';
        }
        $errors = array_merge($errors, Auth::passwordProblems($pw, $email, $name));
        if (!RateLimit::allow('register:' . Http::ip(), 5, 3600)) {
            $errors = ['Too many sign-up attempts from this network. Try again later.'];
        }
        if ($errors) {
            foreach ($errors as $e) {
                Http::flash('error', $e);
            }
            Http::render('register', ['title' => 'Open an account', 'old' => ['email' => $email, 'full_name' => $name]]);
            return;
        }
        $token = Auth::register($email, $name, $pw);
        Http::flash('ok', $token
            ? 'Check your inbox for a confirmation link to finish opening your account.'
            : 'Account created. You can sign in now.');
        if ($token && Config::get('dev_mode')) {
            Http::flash('info', 'DEV MODE - your link: ' . rtrim((string) Config::get('base_url', 'http://127.0.0.1:8080'), '/') . '/verify-email.php?token=' . $token);
        }
        Http::redirect('login.php');
    }

    public static function verifyEmail(): void
    {
        if (Auth::verifyEmail(Http::get('token'))) {
            Http::flash('ok', 'Email confirmed. You can sign in now.');
        } else {
            Http::flash('error', 'That confirmation link is invalid or has expired.');
        }
        Http::redirect('login.php');
    }

    public static function loginForm(): void
    {
        if (Http::user()) {
            Http::redirect('index.php');
        }
        Http::render('login', ['title' => 'Sign in', 'old' => []]);
    }

    public static function login(): void
    {
        Http::requirePostCsrf();
        $email = trim(Http::post('email'));
        $r = Auth::attempt($email, Http::post('password'), Http::ip());
        if (!$r['ok']) {
            Http::flash('error', match ($r['error']) {
                'locked'     => 'Too many attempts. Please wait 15 minutes before trying again.',
                'unverified' => 'Please confirm your email address first (check your inbox).',
                default      => 'Incorrect email or password.',
            });
            Http::render('login', ['title' => 'Sign in', 'old' => ['email' => $email]]);
            return;
        }
        if ($r['need_2fa']) {
            $_SESSION = ['pre2fa' => ['uid' => (int) $r['user']['id'], 'at' => time(), 'tries' => 0], 'csrf' => Http::csrf()];
            Http::redirect('login-2fa.php');
        }
        Http::signIn($r['user']);
        Http::redirect('index.php');
    }

    public static function twoFactorForm(): void
    {
        if (empty($_SESSION['pre2fa'])) {
            Http::redirect('login.php');
        }
        Http::render('twofa', ['title' => 'Two-factor authentication']);
    }

    public static function twoFactor(): void
    {
        Http::requirePostCsrf();
        $p = $_SESSION['pre2fa'] ?? null;
        if (!$p || time() - $p['at'] > 300) {
            unset($_SESSION['pre2fa']);
            Http::flash('error', 'Your sign-in timed out. Please start again.');
            Http::redirect('login.php');
        }
        $user = Auth::find((int) $p['uid']);
        if ($user && Auth::verifySecondFactor($user, Http::post('code'))) {
            Http::signIn($user);
            Http::redirect('index.php');
        }
        $_SESSION['pre2fa']['tries']++;
        if ($_SESSION['pre2fa']['tries'] >= 5) {
            unset($_SESSION['pre2fa']);
            Http::flash('error', 'Too many incorrect codes. Please sign in again.');
            Http::redirect('login.php');
        }
        Http::flash('error', 'That code is not valid. Check the time on your phone and try again, or use a recovery code.');
        Http::render('twofa', ['title' => 'Two-factor authentication']);
    }

    public static function logout(): void
    {
        Http::requirePostCsrf();
        \Bank\Audit::log('auth.logout', '', $_SESSION['uid'] ?? null);
        Http::destroySession();
        Http::flash('ok', 'You have been signed out.');
        Http::redirect('login.php');
    }
}