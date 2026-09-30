<?php
declare(strict_types=1);

/**
 * Marks "this file was reached by going through the app's own bootstrap", not by a direct HTTP
 * request. Files under storage/ (keys.php, the mail log) check for this before returning anything
 * sensitive, so requesting their URL directly - possible here since storage/ sits in the same
 * folder tree the web server serves everything else from - gets a bare 403 instead of the secret.
 */
define('BANK_INTERNAL', true);

/** Minimal PSR-4 style autoloader:  Bank\Foo => src/Foo.php,  Bank\Web\Bar => src/Web/Bar.php */
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Bank\\', 5) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';

// Fail loudly and clearly rather than with an obscure "undefined function" error later.
$missing = array_filter(['openssl', 'pdo_pgsql', 'mbstring'], static fn(string $ext) => !extension_loaded($ext));
if ($missing) {
    $list = implode(', ', $missing);
    $help = "Missing required PHP extension(s): {$list}\n"
        . "Run 'php --ini' to find your php.ini, then uncomment (remove the leading ';' from) the matching\n"
        . "extension= lines (e.g. extension=pdo_pgsql), or install your OS package (e.g. php-pgsql), and restart your web server.\n"
        . "Check with:  php -m | grep -Ei '" . implode('|', $missing) . "'";
    error_log($help);
    // STDERR is only defined when PHP is run from the command line (php-cli) - writing to it under
    // Apache/mod_php/php-fpm (any normal web request) is itself a fatal error, which used to mask
    // this message entirely. error_log() above already reaches the server's error log either way.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $help . "\n");
    } else {
        http_response_code(500);
        echo 'Server misconfiguration: missing PHP extension(s) ' . htmlspecialchars($list, ENT_QUOTES) . '. See the server log for how to fix.';
    }
    exit(1);
}

date_default_timezone_set((string) Bank\Config::get('timezone', 'UTC'));