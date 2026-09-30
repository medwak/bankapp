<?php
declare(strict_types=1);

namespace Bank;

/** RFC 6238 time-based one-time passwords (Google Authenticator, Authy, 1Password ...). */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[\s=-]+/', '', $s) ?? '');
        $bits = '';
        foreach (str_split($s) as $ch) {
            $v = strpos(self::ALPHABET, $ch);
            if ($v === false) {
                throw new \InvalidArgumentException('Invalid base32');
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function code(string $secretB32, int $time, int $digits = 6, int $period = 30): string
    {
        $h = hash_hmac('sha1', pack('J', intdiv($time, $period)), self::base32Decode($secretB32), true);
        $o = ord($h[19]) & 0x0f;
        $v = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($v % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Accept the current step and +/- $window steps of clock drift.
     * Returns the matched time-step (so the caller can refuse to reuse it) or null.
     */
    public static function verify(string $secretB32, string $code, int $lastStep = 0, ?int $now = null, int $window = 1): ?int
    {
        $now ??= time();
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $matched = null;
        for ($i = -$window; $i <= $window; $i++) {
            $t = $now + $i * 30;
            if (hash_equals(self::code($secretB32, $t), $code) && intdiv($t, 30) > $lastStep) {
                $matched = intdiv($t, 30);
            }
        }
        return $matched;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }
}
