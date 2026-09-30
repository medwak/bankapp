<?php
declare(strict_types=1);
/**
 * Shared bootstrap. Every page in this project starts with `require __DIR__ . '/init.php';` -
 * it loads the app, checks the database is reachable and installed (sending you to setup.php the
 * first time), and starts the session. There is no single front controller / router: every page
 * you can visit is a real .php file sitting right here, so the app works on a plain Apache/XAMPP
 * install with no .htaccess, mod_rewrite or virtual host needed - open setup.php (or index.php,
 * which redirects there on a fresh database) and go.
 */
require __DIR__ . '/src/bootstrap.php';

use Bank\Web\Http;

try {
    $installed = Bank\Db::installed();
} catch (Throwable $e) {
    http_response_code(503);
    echo 'Could not reach the PostgreSQL database. Check the db.* settings in config/local.php or the DB_* environment variables.';
    if (Bank\Config::get('dev_mode')) {
        echo ' (' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . ')';
    }
    exit;
}

Http::boot();

$__self = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (!$installed && !in_array($__self, ['setup.php', 'setup-done.php'], true)) {
    Http::redirect('setup.php');
}

/**
 * Every page ends with `dispatch(fn() => SomeController::action())`. Turns an uncaught exception
 * into the same friendly error page the old single front controller gave, instead of a raw stack
 * trace - the one bit of shared plumbing every page needs, kept to a single function call.
 */
function dispatch(callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        error_log((string) $e);
        Http::fail(500, Bank\Config::get('dev_mode') ? $e->getMessage() : 'Something went wrong. Please try again.');
    }
}
