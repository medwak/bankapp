<?php
/**
 * Router used ONLY when driving the app with `php -S` (both this smoke test and anyone using the
 * "no XAMPP, just try it locally" command in the README). It is not part of the deployed app and
 * is never uploaded to a real host.
 *
 * Why it exists: PHP's built-in server, when given a document root with no router script, quietly
 * falls back to running index.php for ANY request that doesn't match a real file - including a
 * typo'd URL or a page that was deliberately removed - instead of returning 404 the way Apache/
 * XAMPP does for a missing file. That made a couple of "this route is gone" checks meaningless
 * under `php -S`. This router restores ordinary behavior: serve/execute the file if it exists,
 * otherwise send a real 404.
 */
$path = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . '/../' . ltrim($path, '/');

if ($path === '/' || (is_file($file) && !str_contains($path, '..'))) {
    return false; // let the built-in server serve/execute it as usual
}

http_response_code(404);
echo '404 Not Found';
