<?php
declare(strict_types=1);

namespace Bank;

/**
 * Know-your-customer tiers.
 *   0 unverified email   - no transfers
 *   1 email verified     - small daily limit
 *   2 ID document verified by staff
 *   3 enhanced due diligence
 * The ID number is encrypted at rest (only the last 4 digits are kept in clear for display).
 */
final class Kyc
{
    public static function submit(int $userId, array $in): array
    {
        $legal = trim((string) ($in['legal_name'] ?? ''));
        $dob = trim((string) ($in['dob'] ?? ''));
        $idType = trim((string) ($in['id_type'] ?? ''));
        $idNo = preg_replace('/\s+/', '', (string) ($in['id_number'] ?? '')) ?? '';
        $addr = trim((string) ($in['address'] ?? ''));

        $errors = [];
        if (mb_strlen($legal) < 3) { $errors[] = 'Enter your full legal name.'; }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        if (!$d || $d->format('Y-m-d') !== $dob) {
            $errors[] = 'Enter your date of birth.';
        } elseif ($d > new \DateTimeImmutable('-18 years')) {
            $errors[] = 'You must be at least 18 to open an account.';
        }
        if (!in_array($idType, ['NATIONAL_ID', 'PASSPORT', 'DRIVING_LICENCE'], true)) { $errors[] = 'Choose an ID type.'; }
        if (!preg_match('/^[A-Za-z0-9]{5,20}$/', $idNo)) { $errors[] = 'ID number must be 5-20 letters or digits.'; }
        if (mb_strlen($addr) < 8) { $errors[] = 'Enter your residential address.'; }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $hits = Screening::check($legal);
        Db::exec(
            'INSERT INTO kyc_profiles(user_id,legal_name,dob,id_type,id_number_enc,id_last4,address,status,screening,submitted_at)
             VALUES(?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT(user_id) DO UPDATE SET legal_name=excluded.legal_name, dob=excluded.dob, id_type=excluded.id_type,
               id_number_enc=excluded.id_number_enc, id_last4=excluded.id_last4, address=excluded.address,
               status=\'PENDING\', screening=excluded.screening, submitted_at=excluded.submitted_at, reviewed_by=NULL, reviewed_at=NULL, note=NULL',
            [$userId, $legal, $dob, $idType, Crypto::encrypt($idNo), substr($idNo, -4), $addr, 'PENDING', json_encode($hits), time()]
        );
        Audit::log('kyc.submit', "user #{$userId}" . ($hits ? ' WATCHLIST HIT' : ''), $userId);
        return ['ok' => true, 'errors' => []];
    }

    public static function review(int $userId, int $reviewerId, bool $approve, int $tier, string $note, bool $overrideScreening = false): void
    {
        Db::tx(function () use ($userId, $reviewerId, $approve, $tier, $note, $overrideScreening) {
            $p = Db::one("SELECT * FROM kyc_profiles WHERE user_id = ? AND status = 'PENDING'", [$userId]);
            if (!$p) {
                throw new LedgerException('No pending KYC submission.');
            }
            if ($userId === $reviewerId) {
                throw new LedgerException('You cannot review your own KYC.');
            }
            if ($approve) {
                if (!in_array($tier, [2, 3], true)) {
                    throw new LedgerException('Approve to tier 2 or 3.');
                }
                if (json_decode((string) $p['screening'], true) && !$overrideScreening) {
                    throw new LedgerException('Watch-list hit: tick the override box to confirm you reviewed it.');
                }
                Db::exec("UPDATE kyc_profiles SET status='APPROVED', reviewed_by=?, reviewed_at=?, assigned_tier=?, note=? WHERE user_id=?", [$reviewerId, time(), $tier, $note, $userId]);
                Db::exec('UPDATE users SET kyc_tier = ? WHERE id = ?', [$tier, $userId]);
            } else {
                Db::exec("UPDATE kyc_profiles SET status='REJECTED', reviewed_by=?, reviewed_at=?, note=? WHERE user_id=?", [$reviewerId, time(), $note, $userId]);
            }
            Audit::log($approve ? 'kyc.approve' : 'kyc.reject', "user #{$userId} tier {$tier}: {$note}", $reviewerId);
        });
    }
}
