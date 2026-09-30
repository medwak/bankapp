<?php
declare(strict_types=1);

namespace Bank;

/** One-time proofs: email-verification links and short numeric step-up codes. */
final class Verification
{
    /** Create a single-use, 24 h email link token. Only its hash is stored. */
    public static function issueEmailToken(int $userId, string $purpose = 'verify'): string
    {
        $token = bin2hex(random_bytes(32));
        Db::exec(
            'INSERT INTO email_tokens(user_id,purpose,token_hash,expires_at) VALUES(?,?,?,?)',
            [$userId, $purpose, hash('sha256', $token), time() + 86400]
        );
        return $token;
    }

    /** @return ?int the user id when the token is valid; the token is consumed. */
    public static function consumeEmailToken(string $token, string $purpose = 'verify'): ?int
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        return Db::tx(function () use ($token, $purpose) {
            $row = Db::one(
                'SELECT id, user_id FROM email_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
                [hash('sha256', $token), $purpose, time()]
            );
            if (!$row) {
                return null;
            }
            Db::exec('UPDATE email_tokens SET used_at = ? WHERE id = ?', [time(), $row['id']]);
            return (int) $row['user_id'];
        });
    }

    /** Issue a 6-digit code (10 min, 5 tries) and email it. Any earlier code for the purpose is void. */
    public static function issueOtp(array $user, string $purpose): string
    {
        $code = sprintf('%06d', random_int(0, 999999));
        Db::tx(function () use ($user, $purpose, $code) {
            Db::exec('UPDATE otp_challenges SET consumed_at = ? WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL', [time(), $user['id'], $purpose]);
            Db::exec(
                'INSERT INTO otp_challenges(user_id,purpose,code_hash,expires_at) VALUES(?,?,?,?)',
                [$user['id'], $purpose, Crypto::mac("otp|{$user['id']}|{$purpose}|{$code}"), time() + 600]
            );
        });
        Mailer::send($user['email'], 'Your verification code', "Your {$purpose} code is {$code}. It expires in 10 minutes. If you did not request it, contact the bank.");
        return $code;
    }

    public static function checkOtp(int $userId, string $purpose, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        return (bool) Db::tx(function () use ($userId, $purpose, $code) {
            $row = Db::one(
                'SELECT * FROM otp_challenges WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1',
                [$userId, $purpose, time()]
            );
            if (!$row || $row['attempts'] >= 5) {
                return false;
            }
            Db::exec('UPDATE otp_challenges SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            if (strlen($code) !== 6 || !hash_equals($row['code_hash'], Crypto::mac("otp|{$userId}|{$purpose}|{$code}"))) {
                return false;
            }
            Db::exec('UPDATE otp_challenges SET consumed_at = ? WHERE id = ?', [time(), $row['id']]);
            return true;
        });
    }
}
