<?php
declare(strict_types=1);

namespace Bank\Web;

use Bank\{Auth, Config, Db, Installer};

/**
 * One-time web setup wizard (setup.php / setup-done.php). Only reachable while the database
 * has no schema yet (Db::installed() === false); once installed, both pages redirect home, so
 * this can be left in place in production without re-exposing itself.
 */
final class SetupController
{
    public static function form(): void
    {
        if (Db::installed()) {
            Http::redirect('index.php');
        }
        Http::render('setup', ['title' => 'Set up the bank', 'old' => []]);
    }

    public static function submit(): void
    {
        if (Db::installed()) {
            Http::redirect('index.php');
        }
        Http::requirePostCsrf();

        $email = trim(Http::post('email'));
        $name = trim(Http::post('full_name'));
        $pw = Http::post('password');
        $demo = Http::post('demo') === '1';
        $errors = [];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $errors[] = 'Enter a valid email address for the first administrator.';
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors[] = 'Enter the administrator\'s full name.';
        }
        if ($pw !== Http::post('password2')) {
            $errors[] = 'The two passwords do not match.';
        }
        $errors = array_merge($errors, Auth::passwordProblems($pw, $email, $name));

        if ($errors) {
            foreach ($errors as $e) {
                Http::flash('error', $e);
            }
            Http::render('setup', ['title' => 'Set up the bank', 'old' => ['email' => $email, 'full_name' => $name, 'demo' => $demo]]);
            return;
        }

        try {
            $creds = Installer::run(['email' => $email, 'name' => $name, 'password' => $pw], $demo);
        } catch (\Throwable $e) {
            Http::flash('error', 'Setup failed: ' . $e->getMessage());
            Http::render('setup', ['title' => 'Set up the bank', 'old' => ['email' => $email, 'full_name' => $name, 'demo' => $demo]]);
            return;
        }

        $_SESSION['setup_creds'] = $creds;
        Http::flash('ok', 'The bank is set up. Sign in with the administrator account you just created' . ($demo ? '; demo staff and customer logins are shown below once.' : '.'));
        Http::redirect('setup-done.php');
    }

    /** Shown once, right after install, so any --demo-equivalent passwords are seen exactly one time. */
    public static function done(): void
    {
        $creds = $_SESSION['setup_creds'] ?? null;
        unset($_SESSION['setup_creds']);
        Http::render('setup_done', ['title' => 'Bank set up', 'creds' => $creds ?? []]);
    }
}
