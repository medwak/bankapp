# Ledger Bank

A small core-banking application in plain PHP 8.1+ (no framework, no Composer packages). It is
**just a website**: a flat folder of `.php` files with `index.php` sitting right at the project
root, exactly like dropping any ordinary PHP project into `htdocs/`. There are no CLI install
scripts, no router, no `.htaccess`/`mod_rewrite`, no virtual host to set up - open the folder in a
browser and a one-time `setup.php` page walks you through the rest.

The accounting core follows the **1980s branch-banking model**: a 4-digit chart of accounts, a
general journal of balanced debit/credit lines, subsidiary ledgers reconciled to control accounts,
3-letter transaction codes, a business-date file, and a nightly **end-of-day batch** with a trial balance.
On top of that it adds modern controls: Argon2id, TOTP 2FA, email verification, KYC tiers,
fraud scoring, and watch-list screening.

> **Demonstration software.** It is a well-tested reference implementation, not a licensed or audited
> banking system. See [Limits](#limits-read-this) before putting real money near it.

## Run it (Apache/XAMPP or plain PHP - flat folder, no config)

Requires PHP 8.1+ with `pdo_pgsql`, `openssl`, `mbstring` (all standard, no PECL/native builds
needed) and a running **PostgreSQL** server (12+). XAMPP bundles MySQL, not PostgreSQL, so on
Windows the easiest route is the installer at <https://www.postgresql.org/download/windows/>; on
Linux/macOS, your package manager (`apt install postgresql`, `brew install postgresql`, etc.).
Whichever way you install it, make sure PHP's `pdo_pgsql` extension is enabled (on XAMPP: open
`C:\xampp\php\php.ini`, find `;extension=pdo_pgsql`, remove the leading `;`, restart Apache;
`php -m | findstr pgsql` confirms it loaded).

**1. Drop the folder in.** Copy this whole project folder into `C:\xampp\htdocs\` (or wherever your
server's webroot is) - e.g. `C:\xampp\htdocs\bankapp\`. `index.php` is right at the top of the
folder, so no document-root, `.htaccess` or virtual-host changes are needed; the folder *is* the
site.

**2. Create the database and a login for the app** (once, using whatever admin access your
Postgres install gives you):

```bash
sudo -u postgres createuser -P ledgerbank      # prompts for a password; use "ledgerbank" to match the default below
sudo -u postgres createdb -O ledgerbank ledgerbank
```

**3. Point the app at it**, only if your Postgres differs from the defaults above (host
`127.0.0.1`, port `5432`, db/user/password all `ledgerbank`). If yours matches, skip this step -
`config/config.php` already has those defaults built in. Otherwise either edit `config/local.php`
(copy the `db` block from `config/config.php`) or set environment variables, which always win:

```bash
export DB_HOST=127.0.0.1 DB_PORT=5432 DB_NAME=ledgerbank DB_USER=ledgerbank DB_PASS=ledgerbank
```

**4. Start Apache** from the XAMPP control panel (and make sure PostgreSQL is running), then open
**<http://localhost/bankapp/>** (adjust the folder name to whatever you called it). With an empty
database the app sends you straight to `setup.php` - a normal web form that creates the schema,
chart of accounts, first business day and opening capital, and creates the **first administrator
(supervisor) login** from the email/name/password you type in. Tick "also create demo staff and
customer logins" to get `alice@example.com`, `bob@example.com` (customers), `teller@bank.test` and
`supervisor@bank.test` (staff) with sample balances - their passwords are shown once on the
confirmation page. Staff accounts (including the one you create in setup) **must enrol 2FA at
first sign-in**.

Need another staff login later (a new teller or supervisor)? Sign in as a supervisor and use
**Staff** in the navigation bar (`staff-users.php`) - no terminal required.

By default every email (verification links, one-time codes) is written to
`storage/mail/outbox.log.php` instead of actually being sent, and in `dev_mode` is also shown
directly on screen after registering/requesting a code - nothing to configure, good for trying the
app out. **To send real email**, set `mail.driver` to `'smtp'` in `config/local.php` and fill in
`mail.host`/`username`/`password` with a real account - for example, with a Gmail address and an
[app password](https://myaccount.google.com/apppasswords):

```php
<?php // config/local.php
return [
    'base_url' => 'http://localhost/bankapp',   // wherever you actually opened the site
    'mail' => [
        'driver'       => 'smtp',
        'host'         => 'smtp.gmail.com',
        'port'         => 587,
        'encryption'   => 'tls',
        'username'     => 'you@gmail.com',
        'password'     => 'your 16-character app password',
        'from_address' => 'you@gmail.com',
        'from_name'    => 'Ledger Bank',
    ],
];
```

Any SMTP provider works the same way (Mailtrap, SendGrid, your own mail server, etc.) - just change
host/port/username/password. Messages are still appended to the log either way. (That file, and
`storage/keys.php`, look like PHP files rather than plain logs/JSON on purpose - each starts with a
one-line guard that returns 403 if it is ever requested directly over HTTP, so the encryption keys
and any OTP codes in the mail log stay safe even though `storage/`
now sits in the same folder tree the web server serves, with no `.htaccess` required for that
protection to work.) `setup.php` refuses to run again once the database is installed, so it is
safe to leave the file in place.

Prefer the command line instead of XAMPP's control panel? Any PHP 8.1+ install works the same way:

```bash
php -S 127.0.0.1:8080          # from the project root - index.php is found automatically
```

One quirk worth knowing if you use `php -S` this way: PHP's built-in server, unlike Apache, quietly
falls back to `index.php` for *any* URL that doesn't match a real file, instead of a proper 404.
Harmless for normal browsing (a typo'd URL just lands you back on the app), but if that matters for
what you're doing, drive it through `tests/router.php` instead - `php -S 127.0.0.1:8080 tests/router.php`
- which restores ordinary "real file or 404" behavior. Apache/XAMPP never has this quirk, so it
never needs that router.

**Running the test suite** (optional - two throw-away databases, separate from your real data):

```bash
sudo -u postgres createdb -O ledgerbank ledgerbank_test
sudo -u postgres createdb -O ledgerbank ledgerbank_smoke
php tests/run.php     # unit/integration checks (wipes & reinstalls ledgerbank_test each scenario)
php tests/smoke.php   # end-to-end HTTP checks, including the setup.php wizard itself (needs php-curl; uses ledgerbank_smoke)
```

To run the end-of-day batch on a schedule instead of by hand, have cron (or Windows Task Scheduler)
hit the app the same way a supervisor would - or add a small scheduled job that calls
`Bank\EndOfDay::run()` directly; there is deliberately no separate CLI entry point to keep for that.

## How the 1980s model maps to the code

| 1980s concept | Here |
|---|---|
| Chart of accounts (`1xxx` assets, `2xxx` liabilities, `3xxx` equity, `4xxx` income, `5xxx` expense) | `gl_accounts`, seeded in `src/Installer.php` |
| Transaction codes (DEP, WDL, TRF, FEE, INT, CAP, REV, OPN) | `txn_types` |
| General journal - every event is a balanced set of lines | `transactions` + `journal_entries`, written only by `Ledger::post()` |
| Control account + subsidiary ledger (customer master file) | `2100`/`2200`/`2300` are control accounts; `customer_accounts` is the subsidiary ledger |
| Business date file; nothing posts to a closed day | `business_days` + DB triggers |
| Day-end batch: interest, reconciliation, trial balance, close | `src/EndOfDay.php` (one DB transaction; any failure changes nothing) |
| Corrections by reversing entry, never by editing | append-only triggers + `Ledger::reverse()` |
| Passbook / statement with running balance | `Accounts::statement()` |
| Account number with check digit | branch(3) + serial(6) + Luhn digit; typos are caught before money moves |
| Maker-checker | supervisors decide fraud holds and KYC; nobody can review their own case |
| Money in integer cents, never floats | `Money`, all `INTEGER` columns |

Integrity rules enforced in the **database**, not only in PHP: journal/transaction/audit rows cannot be
updated or deleted, entries need exactly one positive side, posting to a non-open day is rejected, a closed day
cannot be reopened, and there can be only one open day.

## Modern layers

**Authentication** - Argon2id password hashes (auto-rehash), length + blocklist password policy (NIST 800-63B
style, optional breached-password check), per-email and per-IP lockout, constant-time login (no user
enumeration; registration answers identically for existing emails), session-ID regeneration on login,
idle (10 min) and absolute (8 h) timeouts, session bound to the browser's UA, HttpOnly + SameSite=Strict cookies,
CSRF token on every POST plus same-origin `Origin` check, strict CSP / X-Frame-Options / nosniff.

**2FA and step-up** - RFC 6238 TOTP (verified against the RFC test vectors), replay-proof (a time-step can be used
once), 8 single-use recovery codes, secrets encrypted at rest (AES-256-GCM via openssl). Staff cannot use the system
without 2FA. Transfers above `step_up_amount`, or that the fraud engine scores as suspicious, need a fresh code
(authenticator, or an emailed one-time code for customers without 2FA).

**Verification / KYC** - email verification (single-use, hashed tokens) gates tier 1; ID submission is reviewed by a
supervisor for tier 2/3. ID numbers are encrypted; only the last 4 characters are visible. Each tier has its own
daily transfer limit. Names are screened against a watch-list (fuzzy, order-insensitive); a hit requires an explicit
override to approve.

**Fraud detection** (`src/Fraud.php`) - explainable, weighted rules: amount spike vs. 30-day average, velocity,
first-time beneficiary, structuring just below the reporting threshold, night-time, new device, recent password/2FA
change, failed sign-ins, money-mule fan-in, balance draining, watch-list match. Score maps to
**allow → step-up → hold (funds reserved, supervisor review) → block**. Customers are told only that a review is
needed; staff see every reason. Small everyday payments ignore the "soft" signals to keep friction low.
Weights and thresholds live in `config/config.php`.

**Confirmation of payee** - the sender sees a masked beneficiary name (`B** O*****`) before confirming.

**Idempotency** - form double-submits and retries post exactly once.

**Encryption at rest** - TOTP secrets and KYC ID numbers are encrypted with AES-256-GCM; low-entropy secrets
(one-time codes, recovery codes) are HMAC-SHA256 keyed hashes. Keys live in `storage/keys.php` (0600,
and guarded against direct HTTP access - see [Run it](#run-it-xampp--plain-apache--php-built-in-server)),
generated once by `setup.php`.

## Layout

```
index.php, dashboard.php, login.php, transfer.php, staff.php, staff-*.php, ...
            every page you can visit is a real, flat .php file right here at the project root -
            no router, no front controller, no /public folder. Each one is a couple of lines:
            require init.php, then dispatch() into a controller method.
init.php    shared bootstrap every page requires first (loads the app, checks the DB, starts the session)
app.js, style.css   static assets, also served straight from the project root
config/     config.php (defaults) - override in config/local.php
db/         schema.sql (PostgreSQL: tables, indexes, immutability triggers)
storage/    keys.php + mail/outbox.log.php - both HTTP-guarded (403 if requested directly), created on setup
src/        domain: Ledger, EndOfDay, Accounts, Teller, Reports, Auth, Totp, Fraud, Kyc, Transfers, Installer ...
src/Web/    Http (session/CSRF/headers), Setup/Auth/Customer/Staff controllers - the flat pages just call into these
views/      PHP templates (customer pages; staff/ pages in a line-printer "green bar" style; setup wizard) - each
            one is also HTTP-guarded, since they live outside src/ where the flat pages can require() them directly
tests/      run.php (unit/integration), smoke.php (HTTP end-to-end, including the setup.php wizard)
```

## Limits (read this)

- **Not production-ready banking.** No regulatory reporting, no card/mobile-money/SWIFT/ACH rails, no loans, no
  multi-currency, no branch/teller-drawer accounting, no maker-checker on every staff action, no formal security audit.
- **Concurrency**: `Ledger::post()` locks the specific customer accounts a posting touches
  (`SELECT ... FOR UPDATE`, in a fixed ascending-id order) before it reads or changes any balance. That keeps two
  postings against the *same* account safe while postings against *different* accounts run fully in parallel - no
  single global lock serialising the whole app. It was load-tested with 20 concurrent processes posting at once
  with no gaps or corruption.
- **Keys** live in `storage/keys.php` (0600, HTTP-guarded). Use a KMS/HSM in production and rotate keys with a
  documented key-change record.
- **Email** defaults to a log file; set `mail.driver` to `'smtp'` (see "Run it" above) to send for
  real, or swap `Mailer::send` for a provider API. **SMS OTP, WebAuthn/passkeys and QR codes for TOTP are
  not implemented** (enter the setup key manually). The breached-password lookup is off by default and was not
  exercised against the live service.
- **Fraud** is rules-based; the bundled watch-list is a two-row demo table - load a real list into `watchlist`.
- **No built-in cryptographic tamper-evidence.** The ledger relies on the database's append-only triggers
  (no `UPDATE`/`DELETE` on journal, transaction or audit rows) plus ordinary backups and access control, not a
  hash chain or digital signatures. If you need independently verifiable tamper-evidence on top of that, that is
  a deliberate simplification you would add back in - keep it out of the request path (e.g. a periodic export
  hashed and anchored elsewhere) rather than serialising every posting through it.
- **Going live checklist:** serve over HTTPS only, set `dev_mode` to `false`, set `base_url`, back up `storage/`
  (keys + mail log) and the database, run the end-of-day batch on a schedule, and put a reverse proxy in front
  (the app deliberately ignores `X-Forwarded-For`).
  


  teller onduso  password ar6wnzR5Rb2EvbRNea