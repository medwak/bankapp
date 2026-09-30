<?php
declare(strict_types=1);

namespace Bank;

/**
 * Mailer with two drivers, picked by config('mail.driver'):
 *
 *  - 'log' (default): appends messages to storage/mail/outbox.log.php. Nothing to configure;
 *    this is what lets dev_mode show verification links/codes on screen instead of emailing them.
 *  - 'smtp': actually delivers the message over SMTP (with STARTTLS/implicit TLS and AUTH LOGIN),
 *    using the host/port/username/password in config('mail'). No external library or Composer
 *    package needed - just PHP's built-in streams. Every message is still appended to the log too,
 *    so dev_mode banners and the test suite keep working exactly as before.
 *
 * To turn SMTP on: fill in config/local.php's 'mail' block (or the MAIL_* env vars) with a real
 * account - e.g. a Gmail address with an "app password", or any SMTP provider - and set
 * 'mail.driver' to 'smtp'. See README for a worked example.
 */
final class Mailer
{
    /** Last message body, handy for tests and dev-mode banners. */
    public static string $last = '';

    public static function send(string $to, string $subject, string $body): void
    {
        self::$last = $body;
        self::appendToLog($to, $subject, $body);

        if ((string) Config::get('mail.driver', 'log') !== 'smtp') {
            return;
        }
        try {
            self::sendSmtp($to, $subject, $body);
        } catch (\Throwable $e) {
            // Never let a broken mail server break the request it was triggered from (registration,
            // a transfer, etc.) - the message is already safely in the log above either way.
            error_log('Mailer: SMTP send failed - ' . $e->getMessage());
        }
    }

    private static function appendToLog(string $to, string $subject, string $body): void
    {
        $dir = Config::get('storage') . '/mail';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        // .log.php, not .log: verification links and one-time codes pass through here, and
        // storage/ sits inside the same folder tree the web server serves (see README), so this
        // guard line - skipped when the app requires the file itself, tripped by a direct HTTP
        // request - keeps a stray request to the log's URL from handing out live codes.
        $path = $dir . '/outbox.log.php';
        if (!is_file($path)) {
            file_put_contents($path, "<?php if (!defined('BANK_INTERNAL')) { http_response_code(403); exit; } ?>\n");
        }
        file_put_contents(
            $path,
            sprintf("----- %s -----\nTo: %s\nSubject: %s\n\n%s\n\n", date('c'), $to, $subject, $body),
            FILE_APPEND | LOCK_EX
        );
    }

    /** A minimal SMTP client: connect, optional STARTTLS, AUTH LOGIN, send, quit. */
    private static function sendSmtp(string $to, string $subject, string $body): void
    {
        $host       = (string) Config::get('mail.host', '');
        $port       = (int) Config::get('mail.port', 587);
        $encryption = (string) Config::get('mail.encryption', 'tls');
        $username   = (string) Config::get('mail.username', '');
        $password   = (string) Config::get('mail.password', '');
        $fromAddr   = (string) Config::get('mail.from_address', 'no-reply@localhost');
        $fromName   = (string) Config::get('mail.from_name', 'Ledger Bank');

        if ($host === '') {
            throw new \RuntimeException("mail.driver is 'smtp' but mail.host is empty - set it in config/local.php or MAIL_HOST");
        }

        $transport = $encryption === 'ssl' ? 'ssl://' . $host : $host;
        $fp = @stream_socket_client("{$transport}:{$port}", $errno, $errstr, 10);
        if (!$fp) {
            throw new \RuntimeException("could not connect to {$host}:{$port} ({$errstr})");
        }
        stream_set_timeout($fp, 10);

        try {
            self::expect($fp, 220);
            self::command($fp, 'EHLO ' . self::heloName(), 250);

            if ($encryption === 'tls') {
                self::command($fp, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS negotiation failed');
                }
                self::command($fp, 'EHLO ' . self::heloName(), 250);
            }

            if ($username !== '') {
                self::command($fp, 'AUTH LOGIN', 334);
                self::command($fp, base64_encode($username), 334);
                self::command($fp, base64_encode($password), 235);
            }

            self::command($fp, "MAIL FROM:<{$fromAddr}>", 250);
            self::command($fp, "RCPT TO:<{$to}>", 250);
            self::command($fp, 'DATA', 354);

            $headers = implode("\r\n", [
                'From: ' . self::encodeHeaderWord($fromName) . " <{$fromAddr}>",
                "To: <{$to}>",
                'Subject: ' . self::encodeHeaderWord($subject),
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . preg_replace('/[^a-z0-9.-]/i', '', $host) . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ]);
            $normalized = str_replace(["\r\n", "\r", "\n"], "\r\n", $body);
            // Dot-stuffing per RFC 5321: a line that starts with '.' gets an extra '.' so the SMTP
            // server doesn't mistake it for the end-of-DATA marker.
            $stuffed = preg_replace('/^\./m', '..', $normalized);
            self::write($fp, $headers . "\r\n\r\n" . $stuffed . "\r\n.");
            self::expect($fp, 250);

            self::command($fp, 'QUIT', 221);
        } finally {
            fclose($fp);
        }
    }

    private static function heloName(): string
    {
        return $_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost';
    }

    /** Encodes a header value as RFC 2047 UTF-8 base64 if it has any non-ASCII bytes; plain otherwise. */
    private static function encodeHeaderWord(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function write($fp, string $line): void
    {
        fwrite($fp, $line . "\r\n");
    }

    private static function command($fp, string $line, int $expectCode): string
    {
        self::write($fp, $line);
        return self::expect($fp, $expectCode);
    }

    /** Reads one (possibly multi-line) SMTP reply and throws unless it starts with $expectCode. */
    private static function expect($fp, int $expectCode): string
    {
        $lines = [];
        do {
            $line = fgets($fp, 1024);
            if ($line === false) {
                throw new \RuntimeException('SMTP connection closed unexpectedly');
            }
            $lines[] = $line;
            $more = isset($line[3]) && $line[3] === '-'; // "250-..." continues, "250 ..." is the last line
        } while ($more);

        $reply = implode('', $lines);
        $code = (int) substr($lines[0], 0, 3);
        if ($code !== $expectCode) {
            throw new \RuntimeException("unexpected SMTP reply (wanted {$expectCode}): " . trim($reply));
        }
        return $reply;
    }
}