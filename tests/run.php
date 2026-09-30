<?php
declare(strict_types=1);
/**
 * Self-contained test suite (no PHPUnit needed):  php tests/run.php
 * Runs against the 'ledgerbank_test' PostgreSQL database (create it once, see README) - every
 * scenario wipes and reinstalls that schema (DROP SCHEMA public CASCADE, see
 * Installer::dropAllTables()) for a clean slate, and gets a fresh throw-away storage directory
 * (keys, mail log) in the system temp dir.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

Bank\Config::override('db.name', getenv('BANK_TEST_DB') ?: 'ledgerbank_test');

use Bank\{Accounts, Audit, Auth, Config, Crypto, Db, EndOfDay, Fraud, Installer, Kyc, Ledger, LedgerException, Mailer, Money, RateLimit, Reports, Screening, Teller, Totp, Transfers};

$pass = 0;
$fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail ? "  -> {$detail}" : '') . "\n";
    }
}
function throws(callable $fn, string $needle = ''): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $needle === '' || stripos($e->getMessage(), $needle) !== false;
    }
    return false;
}
function section(string $s): void
{
    echo "\n== {$s}\n";
}
function fresh(string $start = null): array
{
    $dir = sys_get_temp_dir() . '/bank-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    Config::override('storage', $dir);
    Db::reset();
    Crypto::reset();
    $admin = ['email' => 'admin@bank.test', 'name' => 'Ada Admin', 'password' => 'a very strong test passphrase 1'];
    $creds = Installer::run($admin, true, $start, true);   // force = wipe and reinstall the schema
    // deterministic scoring in tests: the "night" rule depends on wall-clock time
    Config::override('fraud.weights.night', 0);
    return $creds;
}
function uid(string $email): int
{
    return (int) Auth::findByEmail($email)['id'];
}
function acct(string $email, string $product = 'DDA'): array
{
    return Db::one('SELECT * FROM customer_accounts WHERE user_id = ? AND product = ? ORDER BY id LIMIT 1', [uid($email), $product]);
}
function bal(int $accountId): int
{
    return (int) Db::val('SELECT ledger_balance FROM customer_accounts WHERE id = ?', [$accountId]);
}

// ---------------------------------------------------------------------------------------
section('Money');
ok('parse 1,250.5', Money::parse('1,250.5') === 125050);
ok('parse rejects negative', Money::parse('-5') === null);
ok('parse rejects 3 decimals', Money::parse('1.234') === null);
ok('fmt', Money::fmt(-123456) === '-1,234.56');

// ---------------------------------------------------------------------------------------
section('Ledger core');
fresh();
$sup = uid('supervisor@bank.test');
$alice = acct('alice@example.com');
$bob = acct('bob@example.com');
ok('opening capital posted, reconciled', Reports::reconcile() === []);
ok('trial balance balances', Reports::trialBalance()['balanced']);
ok('subledgers reconcile after demo funding', Reports::reconcile() === []);
ok('unbalanced entry rejected', throws(fn() => Ledger::post('DEP', 'x', [['gl' => '1010', 'dr' => 100], ['gl' => '3000', 'cr' => 90]]), 'balance'));
ok('one-sided line rejected', throws(fn() => Ledger::post('DEP', 'x', [['gl' => '1010', 'dr' => 100, 'cr' => 100], ['gl' => '3000', 'cr' => 0]])));
ok('control account needs subledger id', throws(fn() => Ledger::post('DEP', 'x', [['gl' => '1010', 'dr' => 100], ['gl' => '2100', 'cr' => 100]]), 'subsidiary'));
ok('non-control account rejects subledger id', throws(fn() => Ledger::post('DEP', 'x', [['gl' => '1010', 'dr' => 100, 'account_id' => $alice['id']], ['gl' => '3000', 'cr' => 100]]), 'not a control'));
ok('savings acct cannot post to 2100', throws(fn() => Ledger::post('DEP', 'x', [['gl' => '1010', 'dr' => 100], ['gl' => '2100', 'cr' => 100, 'account_id' => acct('alice@example.com', 'SAV')['id']]]), 'product'));
ok('unknown GL rejected', throws(fn() => Ledger::post('DEP', 'x', [['gl' => '9999', 'dr' => 1], ['gl' => '3000', 'cr' => 1]])));
ok('unknown txn code rejected', throws(fn() => Ledger::post('ZZZ', 'x', [['gl' => '1010', 'dr' => 1], ['gl' => '3000', 'cr' => 1]])));

$before = bal((int) $alice['id']);
Teller::deposit((int) $alice['id'], 100_00, $sup);
ok('deposit updates master file', bal((int) $alice['id']) === $before + 100_00);
ok('overdraw refused', throws(fn() => Teller::withdraw((int) $alice['id'], $before + 100_00 + 1, $sup), 'insufficient'));
ok('failed post left no trace', bal((int) $alice['id']) === $before + 100_00 && Reports::reconcile() === []);

$r1 = Ledger::post('DEP', 'idem', [['gl' => '1010', 'dr' => 500], ['gl' => '3000', 'cr' => 500]], ['idem_key' => 'k1']);
$r2 = Ledger::post('DEP', 'idem', [['gl' => '1010', 'dr' => 500], ['gl' => '3000', 'cr' => 500]], ['idem_key' => 'k1']);
ok('idempotency key replays same txn', $r1['id'] === $r2['id'] && $r2['replayed']);

ok('journal UPDATE blocked by trigger', throws(fn() => Db::exec('UPDATE journal_entries SET debit = 1 WHERE id = 1'), 'append-only'));
ok('journal DELETE blocked by trigger', throws(fn() => Db::exec('DELETE FROM journal_entries WHERE id = 1'), 'append-only'));
ok('transaction DELETE blocked', throws(fn() => Db::exec('DELETE FROM transactions WHERE id = 1'), 'append-only'));
ok('audit UPDATE blocked', throws(fn() => Db::exec("UPDATE audit_log SET action = 'x'"), 'append-only'));

$t = Teller::deposit((int) $alice['id'], 250_00, $sup);
$balBefore = bal((int) $alice['id']);
$rev = Ledger::reverse($t['id'], 'keyed twice', $sup);
ok('reversal restores balance', bal((int) $alice['id']) === $balBefore - 250_00);
ok('double reversal refused', throws(fn() => Ledger::reverse($t['id'], 'again', $sup), 'already'));
ok('reversal of reversal refused', throws(fn() => Ledger::reverse($rev['id'], 'again', $sup), 'cannot itself'));
ok('still reconciled after reversal', Reports::reconcile() === []);

$stmt = Accounts::statement((int) $alice['id']);
ok('statement running balance matches master file', $stmt['closing'] === bal((int) $alice['id']));
ok('account numbers pass Luhn', Accounts::validNumber($alice['acct_no']) && !Accounts::validNumber(substr($alice['acct_no'], 0, 9) . (((int) $alice['acct_no'][9] + 1) % 10)));

// ---------------------------------------------------------------------------------------
section('Reconciliation catches a corrupted master file');
Db::exec('UPDATE customer_accounts SET ledger_balance = ledger_balance + 1 WHERE id = ?', [$bob['id']]);
ok('reconcile flags the account', count(Reports::reconcile()) >= 1);
$eod = EndOfDay::run($sup);
ok('EOD refuses to close on a reconciliation break', !$eod['ok'] && Ledger::currentDay() === date('Y-m-d'));
Db::exec('UPDATE customer_accounts SET ledger_balance = ledger_balance - 1 WHERE id = ?', [$bob['id']]);
ok('EOD failure rolled back cleanly', Reports::reconcile() === []);

// ---------------------------------------------------------------------------------------
section('End of day');
$day1 = Ledger::currentDay();
$eod = EndOfDay::run($sup);
ok('EOD succeeds', $eod['ok'], implode(' | ', $eod['log']));
ok('day closed, next day open', Ledger::currentDay() === $eod['next'] && Db::val('SELECT status FROM business_days WHERE bdate = ?', [$day1]) === 'CLOSED');
ok('trial-balance snapshot stored', (int) Db::val('SELECT COUNT(*) FROM eod_trial_balance WHERE bdate = ?', [$day1]) === count(Installer::CHART));
ok('cannot post into a closed day (trigger)', throws(fn() => Db::exec(
    "INSERT INTO transactions(type_code,bdate,posted_at,description) VALUES('DEP',?,'t','x')", [$day1]), 'not open'));
ok('closed day cannot be reopened', throws(fn() => Db::exec("UPDATE business_days SET status='OPEN' WHERE bdate = ?", [$day1]), 'closed'));

// ---------------------------------------------------------------------------------------
section('Append-only tables resist a raw-SQL edit even after the day closes');
ok('journal row cannot be edited', throws(fn() => Db::exec("UPDATE journal_entries SET narrative = 'edited by attacker' WHERE txn_id = 2 AND line_no = 1"), 'append-only'));
ok('posted transaction cannot be edited', throws(fn() => Db::exec("UPDATE transactions SET description = 'edited' WHERE id = 2"), 'append-only'));

// ---------------------------------------------------------------------------------------
section('Interest accrual and month-end capitalisation');
fresh('2026-01-30');           // a Friday: next business day is Monday 2 Feb, so 3 days accrue
$sup = uid('supervisor@bank.test');
$sav = acct('alice@example.com', 'SAV');
$eod = EndOfDay::run($sup);
ok('EOD ok', $eod['ok'], implode(' | ', $eod['log']));
ok('next business day skips the weekend', $eod['next'] === '2026-02-02');
// 100,000.00 * 6% * 3/365 = 49.315... -> 49.32
ok('interest accrued for 3 days, rounded half-up', (int) Db::val("SELECT COALESCE(SUM(credit),0) FROM journal_entries WHERE gl_code='2300' AND account_id = ? AND bdate = '2026-01-30'", [$sav['id']]) === 4932);
ok('month-end capitalised into the savings account', bal((int) $sav['id']) === 10_000_000 + 4932);
ok('interest payable cleared', (int) Db::val("SELECT COALESCE(SUM(credit - debit),0) FROM journal_entries WHERE gl_code = '2300'") === 0);
ok('still reconciled', Reports::reconcile() === []);
$st = Reports::statements();
ok('A = L + E + profit', $st['CHECK'] === 0 && $st['EXPENSE'] === 4932);

// ---------------------------------------------------------------------------------------
section('TOTP (RFC 6238 test vectors)');
$rfcSecret = Totp::base32Encode('12345678901234567890');
ok('base32 roundtrip', Totp::base32Decode($rfcSecret) === '12345678901234567890');
ok('T=59         -> 94287082', Totp::code($rfcSecret, 59, 8) === '94287082');
ok('T=1111111109 -> 07081804', Totp::code($rfcSecret, 1111111109, 8) === '07081804');
ok('T=1234567890 -> 89005924', Totp::code($rfcSecret, 1234567890, 8) === '89005924');
ok('T=2000000000 -> 69279037', Totp::code($rfcSecret, 2000000000, 8) === '69279037');
$now = 1_700_000_000;
ok('accepts current and adjacent step', Totp::verify($rfcSecret, Totp::code($rfcSecret, $now), 0, $now) !== null && Totp::verify($rfcSecret, Totp::code($rfcSecret, $now - 30), 0, $now) !== null);
ok('rejects step outside the window', Totp::verify($rfcSecret, Totp::code($rfcSecret, $now - 90), 0, $now) === null);
$step = Totp::verify($rfcSecret, Totp::code($rfcSecret, $now), 0, $now);
ok('rejects replay of an already-used step', Totp::verify($rfcSecret, Totp::code($rfcSecret, $now), $step, $now) === null);

// ---------------------------------------------------------------------------------------
section('Authentication');
fresh();
ok('short password refused', count(Auth::passwordProblems('short')) > 0);
ok('password containing email name refused', count(Auth::passwordProblems('carolmwangi-2026-xyz', 'carolmwangi@example.com')) > 0);
ok('long passphrase accepted', Auth::passwordProblems('correct horse battery staple') === []);

$token = Auth::register('carol@example.com', 'Carol Njeri', 'correct horse battery staple');
ok('registration issues a token', is_string($token) && strlen($token) === 64);
ok('duplicate registration is silent (no enumeration)', Auth::register('carol@example.com', 'Evil', 'another long passphrase!') === null);
ok('unverified user cannot sign in', Auth::attempt('carol@example.com', 'correct horse battery staple', '1.1.1.1')['error'] === 'unverified');
ok('bad token rejected', !Auth::verifyEmail(str_repeat('a', 64)));
ok('email verification works', Auth::verifyEmail($token));
ok('token is single-use', !Auth::verifyEmail($token));
ok('verified user gets tier 1 and a current account', (int) Auth::findByEmail('carol@example.com')['kyc_tier'] === 1 && acct('carol@example.com') !== null);
ok('argon2id hash stored', str_starts_with(Auth::findByEmail('carol@example.com')['password_hash'], '$argon2id$'));

$r = Auth::attempt('carol@example.com', 'correct horse battery staple', '1.1.1.1');
ok('correct password signs in', $r['ok'] && !$r['need_2fa']);
for ($i = 0; $i < 5; $i++) {
    Auth::attempt('carol@example.com', 'wrong-password-' . $i, '2.2.2.2');
}
ok('account locks after 5 failures (even with right password)', Auth::attempt('carol@example.com', 'correct horse battery staple', '3.3.3.3')['error'] === 'locked');
ok('unknown email gives same generic error', Auth::attempt('nobody@example.com', 'whatever whatever', '4.4.4.4')['error'] === 'invalid');

$u = Auth::findByEmail('bob@example.com');
$secret = Totp::generateSecret();
ok('2FA enrolment rejects a wrong code', Auth::enableTotp((int) $u['id'], $secret, '000000') === null);
$codes = Auth::enableTotp((int) $u['id'], $secret, Totp::code($secret, time()));
ok('2FA enrolment returns 8 recovery codes', is_array($codes) && count($codes) === 8);
ok('TOTP secret encrypted at rest', !str_contains((string) Db::val('SELECT totp_secret_enc FROM users WHERE id = ?', [$u['id']]), $secret));
$u = Auth::findByEmail('bob@example.com');
ok('same TOTP code cannot be used twice (replay)', !Auth::verifySecondFactor($u, Totp::code($secret, time())));
RateLimit::clear('2fa:' . $u['id']);
ok('recovery code works once', Auth::verifySecondFactor($u, $codes[0]) && !Auth::verifySecondFactor($u, $codes[0]));
ok('garbage second factor rejected', !Auth::verifySecondFactor($u, 'abcdef'));
ok('new device detected once', Auth::touchDevice((int) $u['id'], 'fp1', 'UA') && !Auth::touchDevice((int) $u['id'], 'fp1', 'UA'));

// ---------------------------------------------------------------------------------------
section('One-time codes (email step-up)');
$u = Auth::findByEmail('alice@example.com');
$code = Bank\Verification::issueOtp($u, 'transfer');
ok('wrong OTP rejected', !Bank\Verification::checkOtp((int) $u['id'], 'transfer', $code === '000000' ? '111111' : '000000'));
ok('right OTP accepted', Bank\Verification::checkOtp((int) $u['id'], 'transfer', $code));
ok('OTP is single-use', !Bank\Verification::checkOtp((int) $u['id'], 'transfer', $code));
$code = Bank\Verification::issueOtp($u, 'transfer');
$wrong = $code === '123456' ? '654321' : '123456';
for ($i = 0; $i < 5; $i++) {
    Bank\Verification::checkOtp((int) $u['id'], 'transfer', $wrong);
}
ok('OTP burns after 5 wrong tries', !Bank\Verification::checkOtp((int) $u['id'], 'transfer', $code));

// ---------------------------------------------------------------------------------------
section('Encryption at rest');
$enc = Crypto::encrypt('A1234567');
ok('roundtrip', Crypto::decrypt($enc) === 'A1234567');
$raw = base64_decode($enc);
$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
ok('bit-flip detected', throws(fn() => Crypto::decrypt(base64_encode($raw))));

// ---------------------------------------------------------------------------------------
section('Watch-list screening');
fresh();
ok('exact hit', count(Screening::check('Sample Sanctioned Person')) === 1);
ok('reordered name hit', count(Screening::check('Person Sanctioned Sample')) === 1);
ok('typo hit', count(Screening::check('Sampel Sanctioned Persn')) === 1);
ok('innocent name clear', Screening::check('Alice Wanjiru') === []);

// ---------------------------------------------------------------------------------------
section('Transfers, limits, fraud engine');
$creds = fresh();
$sup = uid('supervisor@bank.test');
$aliceU = Auth::findByEmail('alice@example.com');
$alice = acct('alice@example.com');
$bob = acct('bob@example.com');
$go = fn(int $amt, array $ctx = [], ?array $user = null, ?string $to = null) =>
    Transfers::initiate($user ?? Auth::findByEmail('alice@example.com'), (int) $alice['id'], $to ?? $bob['acct_no'], $amt, 'test', $ctx);

$r = $go(100_00);
ok('small first transfer posts (new payee alone is not enough)', $r['status'] === Transfers::POSTED, json_encode($r));
ok('both master files moved', bal((int) $bob['id']) === 5_000_000 + 100_00);
ok('transfer reconciled', Reports::reconcile() === []);
ok('bad account number', $go(100, [], null, '0010000009')['status'] === Transfers::ERROR);
ok('unknown but Luhn-valid account', $go(100, [], null, '001999999' . Accounts::luhnCheckDigit('001999999'))['status'] === Transfers::ERROR);
ok('zero amount', $go(0)['status'] === Transfers::ERROR);
ok('cannot spend from someone else\'s account',
    Transfers::initiate($aliceU, (int) $bob['id'], $alice['acct_no'], 100, '', [])['status'] === Transfers::ERROR);
ok('insufficient funds', $go(999_999_999)['status'] === Transfers::ERROR);

$r = $go(30_000_00);
ok('amount over step-up threshold needs second factor', $r['status'] === Transfers::STEP_UP);
$r = $go(30_000_00, ['step_up_done' => true, 'idem_key' => 'dbl-1']);
ok('after step-up it posts', $r['status'] === Transfers::POSTED);
$r2 = $go(30_000_00, ['step_up_done' => true, 'idem_key' => 'dbl-1']);
ok('double-submit with same idempotency key posts once', $r2['ref'] === $r['ref']);
ok('...and only one transfer row', (int) Db::val("SELECT COUNT(*) FROM transfers WHERE amount = 3000000") === 1);

// daily limit for tier 2 = 500,000
$before = bal((int) $alice['id']);
$r = $go(480_000_00, ['step_up_done' => true]);
ok('daily KYC limit enforced', $r['status'] === Transfers::ERROR && str_contains($r['message'], 'daily'), json_encode($r));

// structuring: tier 3 user, just under reporting threshold
Db::exec('UPDATE users SET kyc_tier = 3 WHERE id = ?', [$aliceU['id']]);
Teller::deposit((int) $alice['id'], 3_000_000_00, $sup);
$aliceU = Auth::findByEmail('alice@example.com');
$r = $go(950_000_00, [], $aliceU);
ok('just-under-threshold amount raises the score and asks for step-up', $r['status'] === Transfers::STEP_UP && $r['score'] >= 30 && str_contains(implode(' ', $r['reasons']), 'threshold'), json_encode($r));

// velocity: 4 transfers within the hour then one more
fresh();
$sup = uid('supervisor@bank.test');
$alice = acct('alice@example.com');
$bob = acct('bob@example.com');
for ($i = 0; $i < 4; $i++) {
    Transfers::initiate(Auth::findByEmail('alice@example.com'), (int) $alice['id'], $bob['acct_no'], 10_00, 'v', []);
}
$r = Transfers::initiate(Auth::findByEmail('alice@example.com'), (int) $alice['id'], $bob['acct_no'], 10_00, 'v', []);
ok('5th transfer in an hour triggers velocity rule', $r['status'] === Transfers::STEP_UP && str_contains(implode(' ', $r['reasons']), 'last hour'), json_encode($r));

// new device adds points; mule pattern
$r = Fraud::assess(['user_id' => uid('alice@example.com'), 'from_account_id' => (int) $alice['id'], 'to_account_id' => (int) $bob['id'], 'amount' => 5_000_00, 'payee_name' => 'Bob', 'new_device' => true]);
ok('new device is scored for a material amount', str_contains(implode(' ', $r['reasons']), 'device not seen'));
$r = Fraud::assess(['user_id' => uid('alice@example.com'), 'from_account_id' => (int) $alice['id'], 'to_account_id' => (int) $bob['id'], 'amount' => 50_00, 'payee_name' => 'Bob', 'new_device' => true]);
ok('...but ignored for a small everyday payment', !str_contains(implode(' ', $r['reasons']), 'device not seen'));

// watch-list beneficiary: held, funds reserved, maker-checker
fresh();
$sup = uid('supervisor@bank.test');
$evilUser = Auth::createUser('evil@example.com', 'Sample Sanctioned Person', 'a very long passphrase 1', 'customer', true, 2);
$evil = Accounts::open($evilUser, 'DDA');
$alice = acct('alice@example.com');
$aliceU = Auth::findByEmail('alice@example.com');
$startBal = bal((int) $alice['id']);
$r = Transfers::initiate($aliceU, (int) $alice['id'], $evil['acct_no'], 5_000_00, 'hmm', []);
ok('watch-list beneficiary is HELD, not posted', $r['status'] === Transfers::HELD && isset($r['alert_id']), json_encode($r));
ok('funds reserved: available drops, ledger unchanged', Ledger::available((int) $alice['id']) === $startBal - 5_000_00 && bal((int) $alice['id']) === $startBal);
ok('customer sees no fraud detail', !str_contains($r['message'], 'watch'));
ok('customer cannot overspend reserved funds', Transfers::initiate($aliceU, (int) $alice['id'], acct('bob@example.com')['acct_no'], $startBal - 5_000_00 + 1, '', ['step_up_done' => true])['status'] === Transfers::ERROR);
ok('customer cannot approve own case', throws(fn() => Transfers::approveHeld($r['alert_id'], (int) $aliceU['id'], 'x'), 'own'));
$res = Transfers::approveHeld($r['alert_id'], $sup, 'verified with customer');
ok('supervisor approval posts and releases hold', bal((int) $evil['id']) === 5_000_00 && Ledger::available((int) $alice['id']) === $startBal - 5_000_00 && (int) Db::val('SELECT COUNT(*) FROM holds WHERE released_at IS NULL') === 0);
ok('decided case cannot be decided twice', throws(fn() => Transfers::rejectHeld($r['alert_id'], $sup, 'x'), 'already'));

$r = Transfers::initiate($aliceU, (int) $alice['id'], $evil['acct_no'], 1_000_00, 'again', []);
Transfers::rejectHeld($r['alert_id'], $sup, 'looks fraudulent');
ok('rejection releases the hold, nothing posted', Ledger::available((int) $alice['id']) === bal((int) $alice['id']));

// block: watchlist + structuring range
Db::exec('UPDATE users SET kyc_tier = 3 WHERE id = ?', [$aliceU['id']]);
Teller::deposit((int) $alice['id'], 3_000_000_00, $sup);
$r = Transfers::initiate(Auth::findByEmail('alice@example.com'), (int) $alice['id'], $evil['acct_no'], 950_000_00, 'x', []);
ok('very high score is BLOCKED and logged as a case', $r['status'] === Transfers::BLOCKED && Db::val('SELECT status FROM fraud_alerts WHERE id = ?', [$r['alert_id']]) === 'BLOCKED', json_encode($r));

// ---------------------------------------------------------------------------------------
section('KYC');
fresh();
$sup = uid('supervisor@bank.test');
$bobU = Auth::findByEmail('bob@example.com');
Db::exec('UPDATE users SET kyc_tier = 1 WHERE id = ?', [$bobU['id']]);
$bad = Kyc::submit((int) $bobU['id'], ['legal_name' => 'B', 'dob' => '2015-01-01', 'id_type' => 'X', 'id_number' => '1', 'address' => 'x']);
ok('invalid KYC rejected with reasons', !$bad['ok'] && count($bad['errors']) >= 4);
$good = Kyc::submit((int) $bobU['id'], ['legal_name' => 'Sample Sanctioned Person', 'dob' => '1990-05-05', 'id_type' => 'PASSPORT', 'id_number' => 'A1234567', 'address' => '12 Moi Avenue, Nairobi']);
ok('valid KYC accepted', $good['ok']);
$p = Db::one('SELECT * FROM kyc_profiles WHERE user_id = ?', [$bobU['id']]);
ok('ID number encrypted, last4 kept', !str_contains($p['id_number_enc'], 'A1234567') && $p['id_last4'] === '4567' && Crypto::decrypt($p['id_number_enc']) === 'A1234567');
ok('watch-list hit recorded', str_contains((string) $p['screening'], 'Sample Sanctioned'));
ok('user cannot review own KYC', throws(fn() => Kyc::review((int) $bobU['id'], (int) $bobU['id'], true, 2, ''), 'own'));
ok('approval blocked without screening override', throws(fn() => Kyc::review((int) $bobU['id'], $sup, true, 2, ''), 'watch-list'));
Kyc::review((int) $bobU['id'], $sup, true, 3, 'checked docs', true);
ok('approval raises tier', (int) Auth::findByEmail('bob@example.com')['kyc_tier'] === 3);

// ---------------------------------------------------------------------------------------
section('Final integrity');
ok('subledgers still reconcile after all of the above', Reports::reconcile() === []);
ok('trial balance still balances after all of the above', Reports::trialBalance()['balanced']);
ok('audit log has rows for everything that happened', (int) Db::val('SELECT COUNT(*) FROM audit_log') >= 8);

echo "\n";
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
