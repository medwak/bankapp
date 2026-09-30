-- =====================================================================
--  LEDGER BANK  -  PostgreSQL schema
--  Classic core-banking layout (chart of accounts, general journal,
--  subsidiary ledgers, business days) plus modern security tables.
--  Money is ALWAYS stored as integer minor units (cents).
--
--  Targets PostgreSQL 12+. Run this against an empty database (or let
--  Bank\Installer::run() do it for you via the web setup wizard) - it
--  creates everything from scratch, including the immutability triggers.
-- =====================================================================

-- ---------- GENERAL LEDGER MASTER (chart of accounts) ----------------
CREATE TABLE gl_accounts (
    code              VARCHAR(4)  NOT NULL PRIMARY KEY CHECK (char_length(code) = 4),
    name              VARCHAR(120) NOT NULL,
    type              VARCHAR(10) NOT NULL CHECK (type IN ('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE')),
    normal_side       CHAR(1)     NOT NULL CHECK (normal_side IN ('D','C')),
    is_control        BOOLEAN     NOT NULL DEFAULT FALSE,     -- true = backed by a subsidiary ledger
    subledger_product VARCHAR(3)  NULL CHECK (subledger_product IN ('DDA','SAV')),
    active            BOOLEAN     NOT NULL DEFAULT TRUE
);

-- Transaction codes (the 3-letter codes tellers used to key in)
CREATE TABLE txn_types (
    code VARCHAR(3)   NOT NULL PRIMARY KEY,
    name VARCHAR(120) NOT NULL
);

-- ---------- BUSINESS DATE CONTROL ------------------------------------
CREATE TABLE business_days (
    bdate     VARCHAR(10) NOT NULL PRIMARY KEY,               -- YYYY-MM-DD
    status    VARCHAR(6)  NOT NULL CHECK (status IN ('OPEN','CLOSED')),
    opened_at BIGINT      NOT NULL,
    closed_at BIGINT      NULL
);
-- Partial unique index: at most one row can ever be OPEN at a time.
CREATE UNIQUE INDEX one_open_business_day ON business_days (status) WHERE status = 'OPEN';

-- ---------- USERS & SECURITY -----------------------------------------
CREATE TABLE users (
    id                  SERIAL       PRIMARY KEY,
    email               VARCHAR(191) NOT NULL UNIQUE,         -- always stored/looked-up lower-cased by the app
    full_name           VARCHAR(160) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,                -- Argon2id
    role                VARCHAR(12)  NOT NULL DEFAULT 'customer' CHECK (role IN ('customer','teller','supervisor')),
    status              VARCHAR(10)  NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE','DISABLED')),
    email_verified_at   BIGINT       NULL,
    kyc_tier            SMALLINT     NOT NULL DEFAULT 0 CHECK (kyc_tier BETWEEN 0 AND 3),
    totp_secret_enc     TEXT         NULL,                    -- AES-256-GCM (openssl) at rest
    totp_enabled        BOOLEAN      NOT NULL DEFAULT FALSE,
    totp_last_step      BIGINT       NOT NULL DEFAULT 0,      -- replay protection
    security_changed_at BIGINT       NULL,                    -- password / 2FA change (fraud signal)
    created_at          BIGINT       NOT NULL
);

CREATE TABLE recovery_codes (
    id        SERIAL       PRIMARY KEY,
    user_id   INTEGER      NOT NULL REFERENCES users(id),
    code_hash VARCHAR(191) NOT NULL,
    used_at   BIGINT       NULL
);

CREATE TABLE email_tokens (
    id         SERIAL       PRIMARY KEY,
    user_id    INTEGER      NOT NULL REFERENCES users(id),
    purpose    VARCHAR(20)  NOT NULL,
    token_hash VARCHAR(191) NOT NULL UNIQUE,
    expires_at BIGINT       NOT NULL,
    used_at    BIGINT       NULL
);

CREATE TABLE otp_challenges (
    id          SERIAL       PRIMARY KEY,
    user_id     INTEGER      NOT NULL REFERENCES users(id),
    purpose     VARCHAR(20)  NOT NULL,
    code_hash   VARCHAR(191) NOT NULL,
    expires_at  BIGINT       NOT NULL,
    attempts    INTEGER      NOT NULL DEFAULT 0,
    consumed_at BIGINT       NULL
);

CREATE TABLE login_log (
    id    SERIAL       PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    ip    VARCHAR(45)  NOT NULL,
    ok    SMALLINT     NOT NULL,      -- 0/1; app writes these as literal integers, not PHP booleans
    at    BIGINT       NOT NULL
);
CREATE INDEX login_log_email_at ON login_log(email, at);

CREATE TABLE rate_limits (
    bucket VARCHAR(191) NOT NULL,
    at     BIGINT       NOT NULL
);
CREATE INDEX rate_limits_bucket ON rate_limits(bucket, at);

CREATE TABLE user_devices (
    id          SERIAL       PRIMARY KEY,
    user_id     INTEGER      NOT NULL REFERENCES users(id),
    fingerprint VARCHAR(191) NOT NULL,
    user_agent  VARCHAR(255) NULL,
    first_seen  BIGINT       NOT NULL,
    last_seen   BIGINT       NOT NULL,
    UNIQUE (user_id, fingerprint)
);

-- ---------- KYC / SCREENING ------------------------------------------
CREATE TABLE kyc_profiles (
    user_id        INTEGER      NOT NULL PRIMARY KEY REFERENCES users(id),
    legal_name     VARCHAR(160) NOT NULL,
    dob            VARCHAR(10)  NOT NULL,
    id_type        VARCHAR(20)  NOT NULL,
    id_number_enc  TEXT         NOT NULL,                     -- AES-256-GCM (openssl) at rest
    id_last4       VARCHAR(4)   NOT NULL,
    address        VARCHAR(255) NOT NULL,
    status         VARCHAR(10)  NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING','APPROVED','REJECTED')),
    screening      TEXT         NULL,                         -- JSON of watch-list hits
    submitted_at   BIGINT       NOT NULL,
    reviewed_by    INTEGER      NULL REFERENCES users(id),
    reviewed_at    BIGINT       NULL,
    assigned_tier  SMALLINT     NULL,
    note           VARCHAR(255) NULL
);

CREATE TABLE watchlist (
    id        SERIAL       PRIMARY KEY,
    name      VARCHAR(160) NOT NULL,
    list_name VARCHAR(60)  NOT NULL
);

-- ---------- SUBSIDIARY LEDGER (customer account master) ---------------
CREATE TABLE customer_accounts (
    id              SERIAL      PRIMARY KEY,
    user_id         INTEGER     NOT NULL REFERENCES users(id),
    acct_no         VARCHAR(20) NOT NULL UNIQUE,              -- branch(3)+serial(6)+Luhn check digit
    product         VARCHAR(3)  NOT NULL CHECK (product IN ('DDA','SAV')),
    control_code    VARCHAR(4)  NOT NULL REFERENCES gl_accounts(code),
    status          VARCHAR(10) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE','FROZEN','CLOSED')),
    ledger_balance  BIGINT      NOT NULL DEFAULT 0,           -- cached; reconciled to journal at end-of-day
    allow_overdraft BOOLEAN     NOT NULL DEFAULT FALSE,
    opened_bdate    VARCHAR(10) NOT NULL
);

CREATE TABLE holds (
    id          SERIAL       PRIMARY KEY,
    account_id  INTEGER      NOT NULL REFERENCES customer_accounts(id),
    amount      BIGINT       NOT NULL CHECK (amount > 0),
    reason      VARCHAR(255) NOT NULL,
    alert_id    INTEGER      NULL,
    created_at  BIGINT       NOT NULL,
    released_at BIGINT       NULL
);

-- ---------- GENERAL JOURNAL (append-only) -----------------------------
CREATE TABLE transactions (
    id              SERIAL       PRIMARY KEY,
    -- left NULL for an instant on insert, then filled in with the id-derived reference; Postgres
    -- allows any number of NULLs under a UNIQUE constraint, so concurrent postings never collide.
    txn_ref         VARCHAR(40)  NULL UNIQUE,
    idem_key        VARCHAR(191) NULL UNIQUE,
    type_code       VARCHAR(3)   NOT NULL REFERENCES txn_types(code),
    bdate           VARCHAR(10)  NOT NULL REFERENCES business_days(bdate),
    posted_at       VARCHAR(30)  NOT NULL,                    -- UTC ISO-8601
    user_id         INTEGER      NULL REFERENCES users(id),
    description     VARCHAR(255) NOT NULL,
    reverses_txn_id INTEGER      NULL UNIQUE REFERENCES transactions(id)  -- unique -> at most one reversal per txn
);
CREATE INDEX transactions_bdate ON transactions(bdate, id);

CREATE TABLE journal_entries (
    id         SERIAL       PRIMARY KEY,
    txn_id     INTEGER      NOT NULL REFERENCES transactions(id),
    line_no    INTEGER      NOT NULL,
    bdate      VARCHAR(10)  NOT NULL,
    gl_code    VARCHAR(4)   NOT NULL REFERENCES gl_accounts(code),
    account_id INTEGER      NULL REFERENCES customer_accounts(id),
    debit      BIGINT       NOT NULL DEFAULT 0,
    credit     BIGINT       NOT NULL DEFAULT 0,
    narrative  VARCHAR(255) NOT NULL DEFAULT '',
    CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0) AND (debit + credit) > 0),
    UNIQUE (txn_id, line_no)
);
CREATE INDEX journal_gl_bdate   ON journal_entries(gl_code, bdate);
CREATE INDEX journal_account_gl ON journal_entries(account_id, gl_code);

-- Transfer metadata (queried by limits and fraud rules)
CREATE TABLE transfers (
    id              SERIAL      PRIMARY KEY,
    txn_id          INTEGER     NOT NULL REFERENCES transactions(id),
    user_id         INTEGER     NOT NULL REFERENCES users(id),
    from_account_id INTEGER     NOT NULL REFERENCES customer_accounts(id),
    to_account_id   INTEGER     NOT NULL REFERENCES customer_accounts(id),
    amount          BIGINT      NOT NULL,
    bdate           VARCHAR(10) NOT NULL,
    created_at      BIGINT      NOT NULL
);
CREATE INDEX transfers_user ON transfers(user_id, created_at);
CREATE INDEX transfers_to   ON transfers(to_account_id, created_at);

-- ---------- FRAUD CASES ------------------------------------------------
CREATE TABLE fraud_alerts (
    id              SERIAL       PRIMARY KEY,
    user_id         INTEGER      NOT NULL REFERENCES users(id),
    from_account_id INTEGER      NOT NULL REFERENCES customer_accounts(id),
    to_account_id   INTEGER      NOT NULL REFERENCES customer_accounts(id),
    amount          BIGINT       NOT NULL,
    memo            VARCHAR(255) NOT NULL DEFAULT '',
    score           INTEGER      NOT NULL,
    decision        VARCHAR(20)  NOT NULL,
    reasons         TEXT         NOT NULL,                    -- JSON
    status          VARCHAR(10)  NOT NULL CHECK (status IN ('PENDING','APPROVED','REJECTED','BLOCKED')),
    created_at      BIGINT       NOT NULL,
    reviewed_by     INTEGER      NULL REFERENCES users(id),
    reviewed_at     BIGINT       NULL,
    note            VARCHAR(255) NULL,
    txn_id          INTEGER      NULL REFERENCES transactions(id)
);

-- ---------- END-OF-DAY -------------------------------------------------
CREATE TABLE eod_runs (
    id          SERIAL      PRIMARY KEY,
    bdate       VARCHAR(10) NOT NULL,
    started_at  BIGINT      NOT NULL,
    finished_at BIGINT      NULL,
    run_by      INTEGER     NULL REFERENCES users(id),
    status      VARCHAR(10) NOT NULL CHECK (status IN ('RUNNING','OK','FAILED')),
    log         TEXT        NULL
);

CREATE TABLE eod_trial_balance (
    bdate   VARCHAR(10) NOT NULL,
    gl_code VARCHAR(4)  NOT NULL,
    opening BIGINT      NOT NULL,      -- signed, debit positive
    debits  BIGINT      NOT NULL,
    credits BIGINT      NOT NULL,
    closing BIGINT      NOT NULL,
    PRIMARY KEY (bdate, gl_code)
);

-- ---------- AUDIT TRAIL -------------------------------------------------
CREATE TABLE audit_log (
    id      SERIAL       PRIMARY KEY,
    at      VARCHAR(30)  NOT NULL,
    user_id INTEGER      NULL,
    ip      VARCHAR(45)  NOT NULL,
    action  VARCHAR(60)  NOT NULL,
    detail  VARCHAR(255) NOT NULL
);

-- =====================================================================
--  IMMUTABILITY: history is corrected only by posting reversals.
--  Two small, reusable trigger functions cover every append-only table.
-- =====================================================================

CREATE FUNCTION forbid_update() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION '% is append-only', TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION forbid_delete() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION '% is append-only', TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER journal_no_update BEFORE UPDATE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION forbid_update();
CREATE TRIGGER journal_no_delete BEFORE DELETE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION forbid_delete();

-- One UPDATE is allowed: Ledger::post() fills in txn_ref right after insert, while it is still NULL.
-- Once a row has a reference, it can never be changed again.
CREATE FUNCTION txn_forbid_update() RETURNS trigger AS $$
BEGIN
    IF OLD.txn_ref IS NOT NULL THEN
        RAISE EXCEPTION 'transactions are append-only';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER txn_no_update BEFORE UPDATE ON transactions
    FOR EACH ROW EXECUTE FUNCTION txn_forbid_update();
CREATE TRIGGER txn_no_delete BEFORE DELETE ON transactions
    FOR EACH ROW EXECUTE FUNCTION forbid_delete();

CREATE TRIGGER audit_no_update BEFORE UPDATE ON audit_log
    FOR EACH ROW EXECUTE FUNCTION forbid_update();
CREATE TRIGGER audit_no_delete BEFORE DELETE ON audit_log
    FOR EACH ROW EXECUTE FUNCTION forbid_delete();

CREATE TRIGGER eodtb_no_update BEFORE UPDATE ON eod_trial_balance
    FOR EACH ROW EXECUTE FUNCTION forbid_update();
CREATE TRIGGER eodtb_no_delete BEFORE DELETE ON eod_trial_balance
    FOR EACH ROW EXECUTE FUNCTION forbid_delete();

-- Nothing may be posted to a business day that is not OPEN
CREATE FUNCTION check_day_open() RETURNS trigger AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM business_days WHERE bdate = NEW.bdate AND status = 'OPEN') THEN
        RAISE EXCEPTION 'business day is not open';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER txn_day_open BEFORE INSERT ON transactions
    FOR EACH ROW EXECUTE FUNCTION check_day_open();
CREATE TRIGGER journal_day_open BEFORE INSERT ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION check_day_open();

-- A closed day can never be reopened or deleted
CREATE FUNCTION day_forbid_reopen() RETURNS trigger AS $$
BEGIN
    IF OLD.status = 'CLOSED' THEN
        RAISE EXCEPTION 'a closed business day cannot be changed';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER day_no_reopen BEFORE UPDATE ON business_days
    FOR EACH ROW EXECUTE FUNCTION day_forbid_reopen();
CREATE TRIGGER day_no_delete BEFORE DELETE ON business_days
    FOR EACH ROW EXECUTE FUNCTION forbid_delete();
