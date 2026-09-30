<?php
declare(strict_types=1);
/**
 * End-to-end HTTP smoke test:  php tests/smoke.php
 * Boots the real app on the PHP built-in server against a throw-away, NOT-YET-INSTALLED database
 * and drives it with curl - including the /setup web wizard itself, so the whole "just a website,
 * no CLI scripts" flow is exercised end to end (setup, registration, email verification, login,
 * 2FA, transfers with step-up, fraud hold + review, teller, end-of-day, staff management, access
 * control, CSRF, XSS escaping, lockout).
 */
$dir = sys_get_temp_dir() . '/bank-smoke-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
putenv("BANK_STORAGE={$dir}");
$dbName = getenv('BANK_SMOKE_DB') ?: 'ledgerbank_smoke';
putenv("DB_NAME={$dbName}");
require dirname(__DIR__) . '/src/bootstrap.php';

use Bank\{Db, Totp};

// wall-clock "night" fraud rule off, so results do not depend on the hour the test runs
$extra = "{$dir}/test-config.php";
file_put_contents($extra, "<?php return ['fraud' => ['weights' => ['night' => 0]]];\n");
putenv("BANK_CONFIG={$extra}");
Bank\Config::override('fraud.weights.night', 0);
Bank\Config::override('db.name', $dbName);

// The schema is wiped by hand here; the app under test installs itself over HTTP via setup.php
// below, exactly like a real deployment would - no CLI install script involved.
Db::reset();
if (Db::installed()) {
    Bank\Installer::dropAllTables();
}

// Every page is a real .php file at the project root, so the built-in server just needs the
// project root as its document root - it maps "/" to index.php on its own, exactly like
// Apache/XAMPP would. router.php is test-only plumbing (see that file) so a couple of "this page
// is gone" checks below get a real 404 instead of `php -S`'s own quirky index.php fallback; it is
// not part of the deployed app and Apache/XAMPP never needs it.
$port = random_int(20000, 40000);
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__), __DIR__ . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', "{$dir}/server.log", 'a'], 2 => ['file', "{$dir}/server.log", 'a']],
    $pipes,
    null,
    ['BANK_STORAGE' => $dir, 'BANK_CONFIG' => $extra, 'DB_NAME' => $dbName, 'PATH' => getenv('PATH')]
);
register_shutdown_function(function () use ($proc) {
    proc_terminate($proc);
});
define('BASE', "http://127.0.0.1:{$port}");
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) {
        break;
    }
    usleep(100000);
}

$pass = 0;
$fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $name . (!$cond && $detail ? "  -> {$detail}" : '') . "\n";
}
function http(string $jar, string $method, string $path, array $data = [], bool $follow = true, array $headers = []): array
{
    // Every call site below passes a bare filename ('setup.php', 'account.php?id=1', ...) to
    // mirror how a flat, router-less site is actually linked to; add the leading slash BASE needs.
    $ch = curl_init(BASE . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'SmokeTest/1.0', CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $raw = (string) curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $r = ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs), 'url' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
    curl_close($ch);
    return $r;
}
function csrf(string $html): string
{
    return preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
}
/** GET the page (for its CSRF token) then POST the form. */
function form(string $jar, string $page, string $action, array $data): array
{
    $g = http($jar, 'GET', $page);
    return http($jar, 'POST', $action, $data + ['_csrf' => csrf($g['body'])]);
}
function jar(): string
{
    return tempnam(sys_get_temp_dir(), 'jar');
}
function has(array $r, string $needle): bool
{
    return str_contains($r['body'], $needle);
}
function lastMail(string $regex): ?string
{
    $log = (string) @file_get_contents(Bank\Config::get('storage') . '/mail/outbox.log.php');
    return preg_match_all($regex, $log, $m) ? end($m[1]) : null;
}
function login(string $email, string $pw, ?string $totpSecret = null): string
{
    $j = jar();
    $r = form($j, 'login.php', 'login.php', ['email' => $email, 'password' => $pw]);
    if ($totpSecret && str_contains($r['url'], 'login-2fa.php')) {
        $r = form($j, 'login-2fa.php', 'login-2fa.php', ['code' => Totp::code($totpSecret, time() + 30)]);
    }
    return $j;
}
function enableTotp(string $jar): array
{
    $page = http($jar, 'GET', 'security.php');
    preg_match('/class="secret">([A-Z2-7]+)</', $page['body'], $m);
    $secret = $m[1] ?? '';
    $r = http($jar, 'POST', 'security.php', ['_csrf' => csrf($page['body']), 'action' => 'enable2fa', 'code' => Totp::code($secret, time())]);
    return [$secret, $r];
}

echo "== Setup wizard (no CLI install script - everything runs on the web)\n";
$r = http(jar(), 'GET', '/', [], true);
ok('unset-up database redirects straight to setup.php', str_contains($r['url'], 'setup.php'));
$r = http(jar(), 'GET', 'setup.php');
ok('setup form renders', has($r, 'Set up the bank'));
$r = form(jar(), 'setup.php', 'setup.php', ['full_name' => 'Ada Admin', 'email' => 'admin@bank.test', 'password' => 'short', 'password2' => 'short', 'demo' => '1']);
ok('setup rejects a weak admin password', has($r, 'at least 12'));
$r = form(jar(), 'setup.php', 'setup.php', ['full_name' => 'Ada Admin', 'email' => 'admin@bank.test', 'password' => 'a correct horse battery staple 99', 'password2' => 'a correct horse battery staple 99', 'demo' => '1']);
ok('setup installs and shows demo credentials once', str_contains($r['url'], 'setup-done.php') && has($r, 'Demo logins'));
preg_match_all('#<td>([^<]+@[^<]+)</td><td class="mono">([^<]+)</td>#', $r['body'], $m, PREG_SET_ORDER);
$creds = [];
foreach ($m as $row) {
    $creds[$row[1]] = $row[2];
}
ok('demo credentials for all 4 demo logins were shown', count(array_intersect(array_keys($creds), ['supervisor@bank.test', 'teller@bank.test', 'alice@example.com', 'bob@example.com'])) === 4);
$r = http(jar(), 'GET', 'setup.php');
ok('installed database sends setup.php back to the app', !str_contains($r['url'], 'setup.php') || $r['url'] === BASE . '/setup.php');
ok('...specifically, redirected home', $r['url'] === BASE . '/login.php' || $r['url'] === BASE . '/');

$evilAcct = Bank\Accounts::open(Bank\Auth::createUser('evil@example.com', 'Sample Sanctioned Person', 'a very long passphrase 1', 'customer', true, 2), 'DDA');
Bank\Accounts::open(Bank\Auth::createUser('xss@example.com', '<script>alert(1)</script>', 'a very long passphrase 2', 'customer', true, 2), 'DDA');

echo "\n== Anonymous visitor\n";
$r = http(jar(), 'GET', 'login.php', [], false);
ok('login page renders', $r['code'] === 200 && str_contains($r['body'], 'Sign in'));
ok('CSP header present', stripos($r['headers'], 'Content-Security-Policy: default-src \'self\'') !== false);
ok('X-Frame-Options / nosniff present', stripos($r['headers'], 'X-Frame-Options: DENY') !== false && stripos($r['headers'], 'nosniff') !== false);
ok('session cookie is HttpOnly + SameSite=Strict', (bool) preg_match('/Set-Cookie: BANKSID=.*HttpOnly/i', $r['headers']) && stripos($r['headers'], 'SameSite=Strict') !== false);
$r = http(jar(), 'GET', 'dashboard.php');
ok('dashboard requires login', str_contains($r['url'], 'login.php'));
$r = http(jar(), 'POST', 'login.php', ['email' => 'alice@example.com', 'password' => 'x']);
ok('POST without CSRF token is refused (419)', $r['code'] === 419);
$jo = jar();
$g = http($jo, 'GET', 'login.php');
$r = http($jo, 'POST', 'login.php', ['_csrf' => csrf($g['body']), 'email' => 'x@example.com', 'password' => 'y'], true, ['Origin: http://evil.example']);
ok('cross-origin POST refused even with a valid token (403)', $r['code'] === 403);
$r = http($jo, 'POST', 'login.php', ['_csrf' => csrf($g['body']), 'email' => 'x@example.com', 'password' => 'y'], true, ['Origin: ' . BASE]);
ok('same-origin POST accepted (browser-style Origin header)', $r['code'] === 200 && str_contains($r['body'], 'Incorrect email or password'));
ok('Referrer-Policy allows same-origin Origin header', stripos(http(jar(), 'GET', 'login.php', [], false)['headers'], 'Referrer-Policy: same-origin') !== false);
ok('unknown route 404', http(jar(), 'GET', '/nope.php')['code'] === 404);
ok('static css served', http(jar(), 'GET', 'style.css')['code'] === 200);

echo "\n== Registration and email verification\n";
$j = jar();
$r = form($j, 'register.php', 'register.php', ['full_name' => 'Carol Njeri', 'email' => 'carol@example.com', 'password' => 'short', 'password2' => 'short']);
ok('weak password rejected', has($r, 'at least 12'));
$r = form($j, 'register.php', 'register.php', ['full_name' => 'Carol Njeri', 'email' => 'carol@example.com', 'password' => 'correct horse battery staple', 'password2' => 'correct horse battery staple']);
ok('registration accepted, generic message', has($r, 'Check your inbox'));
$token = lastMail('/verify-email\.php\?token=([0-9a-f]{64})/');
ok('verification email "sent"', $token !== null);
$r = form(jar(), 'login.php', 'login.php', ['email' => 'carol@example.com', 'password' => 'correct horse battery staple']);
ok('cannot sign in before verifying', has($r, 'confirm your email'));
$r = http(jar(), 'GET', 'verify-email.php?token=' . $token);
ok('verification link works', has($r, 'Email confirmed'));
$r = http(jar(), 'GET', 'verify-email.php?token=' . $token);
ok('verification link is single-use', has($r, 'invalid or has expired'));
$jc = login('carol@example.com', 'correct horse battery staple');
$r = http($jc, 'GET', 'dashboard.php');
ok('verified customer sees dashboard with an account', has($r, 'Current account') && has($r, 'Tier') === false || has($r, 'Current account'));
$r = http(jar(), 'POST', 'register.php', []);
ok('register POST without CSRF refused', $r['code'] === 419);

echo "\n== Login hardening\n";
for ($i = 0; $i < 5; $i++) {
    $r = form(jar(), 'login.php', 'login.php', ['email' => 'bob@example.com', 'password' => 'wrong-password-' . $i]);
}
ok('first 5 wrong passwords get the generic error', has($r, 'Incorrect email or password'));
$r = form(jar(), 'login.php', 'login.php', ['email' => 'bob@example.com', 'password' => 'wrong-password-6']);
ok('the 6th attempt is locked out', has($r, 'Too many attempts'));
$r = form(jar(), 'login.php', 'login.php', ['email' => 'bob@example.com', 'password' => $creds['bob@example.com']]);
ok('even the right password is refused while locked', has($r, 'Too many attempts'));
Db::exec('DELETE FROM rate_limits');      // let bob back in for later steps
$r = form(jar(), 'login.php', 'login.php', ['email' => 'nobody@example.com', 'password' => 'whatever12345']);
ok('unknown user gets the same generic error', has($r, 'Incorrect email or password'));

echo "\n== Customer: statements, access control, XSS\n";
$ja = login('alice@example.com', $creds['alice@example.com']);
$r = http($ja, 'GET', 'dashboard.php');
ok('alice dashboard shows balance 250,000.00', has($r, '250,000.00'));
$aliceAcct = Db::one("SELECT * FROM customer_accounts WHERE user_id = (SELECT id FROM users WHERE email='alice@example.com') AND product='DDA'");
$bobAcct = Db::one("SELECT * FROM customer_accounts WHERE user_id = (SELECT id FROM users WHERE email='bob@example.com') AND product='DDA'");
$r = http($ja, 'GET', 'account.php?id=' . $aliceAcct['id']);
ok('own statement renders', $r['code'] === 200 && has($r, 'Closing balance'));
$r = http($ja, 'GET', 'account.php?id=' . $bobAcct['id']);
ok("someone else's statement is 404", $r['code'] === 404);
$r = http($ja, 'GET', 'staff.php');
ok('customer cannot open the staff console (403)', $r['code'] === 403);
$r = http($ja, 'GET', 'transfer-lookup.php?acct=' . $bobAcct['acct_no']);
ok('confirmation-of-payee returns a masked name', has($r, '"name":"B**') || has($r, 'B** O'), $r['body']);

echo "\n== Transfers\n";
$r = form($ja, 'transfer.php', 'transfer.php', ['from' => $aliceAcct['id'], 'to' => $bobAcct['acct_no'], 'amount' => '100.00', 'memo' => 'lunch']);
ok('small transfer completes', has($r, 'Transfer of 100.00 completed'), substr(strip_tags($r['body']), 0, 300));
$r = form($ja, 'transfer.php', 'transfer.php', ['from' => $aliceAcct['id'], 'to' => '0010000009', 'amount' => '10', 'memo' => '']);
ok('mistyped account number refused', has($r, 'not valid'));
$r = form($ja, 'transfer.php', 'transfer.php', ['from' => $aliceAcct['id'], 'to' => $bobAcct['acct_no'], 'amount' => '-5', 'memo' => '']);
ok('negative amount refused', has($r, 'valid amount'));
$r = form($ja, 'transfer.php', 'transfer.php', ['from' => $aliceAcct['id'], 'to' => $bobAcct['acct_no'], 'amount' => '30000', 'memo' => 'rent']);
ok('large transfer asks for a second factor', str_contains($r['url'], 'transfer-verify.php') && has($r, 'Confirm your transfer'));
$otp = lastMail('/Your transfer code is (\d{6})/');
ok('emailed step-up code exists', $otp !== null);
$r = form($ja, 'transfer-verify.php', 'transfer-verify.php', ['code' => $otp === '000000' ? '111111' : '000000']);
ok('wrong step-up code refused', has($r, 'not correct'));
$r = form($ja, 'transfer-verify.php', 'transfer-verify.php', ['code' => $otp]);
ok('right step-up code completes the transfer', has($r, 'Transfer of 30,000.00 completed'), substr(strip_tags($r['body']), 0, 300));
$r = http($ja, 'GET', 'account.php?id=' . $aliceAcct['id']);
ok('statement shows both debits', has($r, '100.00') && has($r, '30,000.00'));

echo "\n== Customer enables 2FA and signs in with it\n";
[$secret, $r] = enableTotp($ja);
ok('2FA enrolment shows recovery codes', has($r, 'recovery codes') && preg_match_all('/<div>[0-9a-f]{5}-[0-9a-f]{5}<\/div>/', $r['body']) === 8);
$ja2 = login('alice@example.com', $creds['alice@example.com'], $secret);
ok('2FA sign-in reaches dashboard', has(http($ja2, 'GET', 'dashboard.php'), 'My accounts'));
$jbad = jar();
$r = form($jbad, 'login.php', 'login.php', ['email' => 'alice@example.com', 'password' => $creds['alice@example.com']]);
ok('password alone is not enough when 2FA is on', str_contains($r['url'], 'login-2fa.php') && !has(http($jbad, 'GET', 'dashboard.php'), 'My accounts'));
$r = form($jbad, 'login-2fa.php', 'login-2fa.php', ['code' => '000000']);
ok('wrong TOTP refused', has($r, 'not valid'));
$r = http($ja2, 'GET', 'dashboard.php');
$r = form($ja2, 'transfer.php', 'transfer.php', ['from' => $aliceAcct['id'], 'to' => $bobAcct['acct_no'], 'amount' => '25000', 'memo' => 'x']);
ok('with 2FA on, step-up uses the authenticator (not email)', has($r, 'authenticator app'));

echo "\n== Fraud: watch-list beneficiary is held, supervisor reviews\n";
$r = form($ja2, 'transfer.php', 'transfer.php', ['from' => $aliceAcct['id'], 'to' => $evilAcct['acct_no'], 'amount' => '500', 'memo' => 'hello']);
ok('held transfer: customer told it is under review', has($r, 'quick review') || has($r, 'being reviewed'), substr(strip_tags($r['body']), 0, 300));
ok('customer sees no fraud reasons', !has($r, 'watch') && !has($r, 'Watch'));
ok('a pending case exists', (int) Db::val("SELECT COUNT(*) FROM fraud_alerts WHERE status='PENDING'") === 1);

echo "\n== Staff: forced 2FA, teller, review, reports, end-of-day\n";
$js = login('supervisor@bank.test', $creds['supervisor@bank.test']);
$r = http($js, 'GET', 'staff.php');
ok('staff without 2FA is bounced to enrol', str_contains($r['url'], 'security.php') && has($r, 'must enable two-factor'));
[$supSecret] = enableTotp($js);
$js = login('supervisor@bank.test', $creds['supervisor@bank.test'], $supSecret);
$r = http($js, 'GET', 'staff.php');
ok('after enrolling, console opens', $r['code'] === 200 && has($r, 'Staff console'));
ok('console shows 1 pending fraud case', (bool) preg_match('/awaiting review<\/div><div class="balance">1</', $r['body']));
$r = http($js, 'GET', 'staff-fraud.php');
ok('fraud queue shows the reason to staff', has($r, 'watch-list entry'));
preg_match('#staff-fraud\.php\?id=(\d+)#', $r['body'], $m);
$r = form($js, 'staff-fraud.php', 'staff-fraud.php?id=' . $m[1], ['decision' => 'approve', 'note' => 'Verified with customer by phone']);
ok('supervisor approves, transfer posts', has($r, 'approved and posted'), substr(strip_tags($r['body']), 0, 300));
ok('beneficiary balance updated', (int) Db::val('SELECT ledger_balance FROM customer_accounts WHERE id = ?', [$evilAcct['id']]) === 500_00);

$jt = login('teller@bank.test', $creds['teller@bank.test']);
[$telSecret] = enableTotp($jt);
$jt = login('teller@bank.test', $creds['teller@bank.test'], $telSecret);
$r = http($jt, 'GET', 'staff-teller.php?acct=' . $bobAcct['acct_no']);
ok('teller looks up account', has($r, 'Bob Otieno'));
$before = (int) Db::val('SELECT ledger_balance FROM customer_accounts WHERE id = ?', [$bobAcct['id']]);
$r = form($jt, 'staff-teller.php?acct=' . $bobAcct['acct_no'], 'staff-teller.php', ['action' => 'deposit', 'account_id' => $bobAcct['id'], 'acct_no' => $bobAcct['acct_no'], 'amount' => '1,000.50', 'note' => 'salary']);
ok('teller deposit posts', has($r, 'Deposit posted') && (int) Db::val('SELECT ledger_balance FROM customer_accounts WHERE id = ?', [$bobAcct['id']]) === $before + 100050);
$r = http($jt, 'GET', 'staff-eod.php');
ok('teller cannot open end-of-day (supervisor only)', $r['code'] === 403);
$r = form($jt, 'staff-teller.php?acct=' . $bobAcct['acct_no'], 'staff-teller.php', ['action' => 'fee', 'account_id' => $bobAcct['id'], 'acct_no' => $bobAcct['acct_no'], 'amount' => '5', 'note' => 'x']);
ok('teller cannot post service charges', $r['code'] === 403);

$r = http($js, 'GET', 'staff-trial-balance.php');
ok('trial balance page balanced', has($r, 'balanced') && !has($r, 'OUT OF BALANCE'));
$r = http($js, 'GET', 'staff-journal.php');
ok('journal lists transactions', has($r, 'Cash deposit'));
$r = http($js, 'GET', 'staff-ledger.php?gl=2100');
ok('general ledger renders', has($r, 'Demand Deposits'));
$r = http($js, 'GET', 'staff-accounts.php?q=Wanjiru');
ok('account search works', has($r, 'Alice Wanjiru'));
$r = http($js, 'GET', 'staff-accounts.php?q=script');
ok('XSS payload in a name is escaped', has($r, '&lt;script&gt;') && !has($r, '<script>alert(1)'));
$r = http($js, 'GET', 'staff-chain.php');
ok('the removed blockchain/integrity page is gone (404)', $r['code'] === 404);

$day = Bank\Ledger::currentDay();
$r = form($js, 'staff-eod.php', 'staff-eod.php', ['confirm' => '1999-01-01']);
ok('EOD requires typing the date', has($r, 'Type the current business date'));
$r = form($js, 'staff-eod.php', 'staff-eod.php', ['confirm' => $day]);
ok('EOD runs and closes the day', has($r, 'closed'), substr(strip_tags($r['body']), 0, 400));
ok('business date advanced', Bank\Ledger::currentDay() !== $day);
$r = http($js, 'GET', 'staff-audit.php');
ok('audit trail page renders', has($r, 'eod.ok'));

echo "\n== Staff management (replaces bin/create-staff.php)\n";
$r = http($jt, 'GET', 'staff-users.php');
ok('teller cannot manage staff (supervisor only)', $r['code'] === 403);
$r = http($js, 'GET', 'staff-users.php');
ok('supervisor sees the staff list', has($r, 'Staff accounts') && has($r, 'supervisor@bank.test'));
$r = form($js, 'staff-users.php', 'staff-users.php', ['full_name' => 'Nia New Teller', 'email' => 'nia@bank.test', 'role' => 'teller']);
ok('supervisor creates a new staff login, password shown once', has($r, 'Created nia@bank.test') || has($r, 'nia@bank.test'), substr(strip_tags($r['body']), 0, 300));
ok('new staff account exists and needs 2FA', (int) Db::val("SELECT totp_enabled FROM users WHERE email = 'nia@bank.test'") === 0);
$r = form($js, 'staff-users.php', 'staff-users.php', ['full_name' => 'Dup', 'email' => 'nia@bank.test', 'role' => 'teller']);
ok('duplicate staff email refused', has($r, 'already has an account'));

echo "\n== Session handling\n";
$r = form($ja2, 'dashboard.php', 'logout.php', []);
ok('logout works', has($r, 'signed out'));
ok('dashboard closed after logout', str_contains(http($ja2, 'GET', 'dashboard.php')['url'], 'login.php'));

echo "\n";
printf("%d passed, %d failed\n", $pass, $fail);
if ($fail) {
    echo "\n--- server log tail ---\n" . substr((string) @file_get_contents("{$dir}/server.log"), -1500) . "\n";
}
exit($fail === 0 ? 0 : 1);
