<?php
declare(strict_types=1);

namespace Bank;

/** Creates the database, chart of accounts, keys, first business day and opening capital. */
final class Installer
{
    public const CHART = [
        // code, name, type, normal side, control?, subledger product
        ['1010', 'Cash in Vault',                 'ASSET',     'D', 0, null],
        ['1020', 'Balances with Central Bank',    'ASSET',     'D', 0, null],
        ['1200', 'Loans to Customers',            'ASSET',     'D', 0, null],
        ['2100', 'Demand Deposits (Current A/c)', 'LIABILITY', 'C', 1, 'DDA'],
        ['2200', 'Savings Deposits',              'LIABILITY', 'C', 1, 'SAV'],
        ['2300', 'Interest Payable',              'LIABILITY', 'C', 1, 'SAV'],
        ['2900', 'Suspense / Clearing',           'LIABILITY', 'C', 0, null],
        ['3000', 'Share Capital',                 'EQUITY',    'C', 0, null],
        ['3100', 'Retained Earnings',             'EQUITY',    'C', 0, null],
        ['4100', 'Fee & Commission Income',       'INCOME',    'C', 0, null],
        ['5100', 'Interest Expense',              'EXPENSE',   'D', 0, null],
        ['5200', 'Operating Expenses',            'EXPENSE',   'D', 0, null],
    ];

    public const TXN_TYPES = [
        'OPN' => 'Opening balance / capital injection',
        'DEP' => 'Cash deposit',
        'WDL' => 'Cash withdrawal',
        'TRF' => 'Account transfer',
        'FEE' => 'Service charge',
        'INT' => 'Interest accrual',
        'CAP' => 'Interest capitalisation',
        'REV' => 'Reversal',
    ];

    /**
     * Drops and recreates the public schema, wiping every table in one shot. Public so tests can
     * reset a scratch database to the same not-yet-installed state a brand new database is in.
     */
    public static function dropAllTables(): void
    {
        Db::pdo()->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
    }

    public static function randomPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 18; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /**
     * Builds the schema, chart of accounts and opening capital, then creates the first
     * supervisor login from $admin (run from the /setup web page - see SetupController).
     *
     * @param array{email:string,name:string,password:string} $admin
     * @return array<string,string> extra demo credentials created (email => password), empty when demo is off
     */
    public static function run(array $admin, bool $demo = false, ?string $startDate = null, bool $force = false): array
    {
        $storage = (string) Config::get('storage');
        foreach ([$storage, "{$storage}/mail"] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }
        Db::reset();
        if (Db::installed() && !$force) {
            throw new \RuntimeException('Database is already installed (found data in gl_accounts).');
        }
        // Clean slate either way - not just when $force is set. This also covers someone who
        // imported db/schema.sql by hand before ever running this wizard: Db::installed() rightly
        // says "not installed" (nothing is seeded yet), but the tables/triggers it created are
        // still sitting there, and schema.sql would fail with "relation already exists" without
        // dropping them first.
        self::dropAllTables();
        if ($force || !is_file(Crypto::keyfilePath())) {
            Crypto::generateKeyfile();
        }
        Db::pdo()->exec((string) file_get_contents(dirname(__DIR__) . '/db/schema.sql'));

        Db::tx(function () use ($startDate, $admin) {
            foreach (self::CHART as [$code, $name, $type, $side, $ctl, $prod]) {
                Db::exec('INSERT INTO gl_accounts(code,name,type,normal_side,is_control,subledger_product) VALUES(?,?,?,?,?,?)', [$code, $name, $type, $side, $ctl, $prod]);
            }
            foreach (self::TXN_TYPES as $code => $name) {
                Db::exec('INSERT INTO txn_types(code,name) VALUES(?,?)', [$code, $name]);
            }
            foreach ([['Sample Sanctioned Person', 'DEMO-WATCHLIST'], ['Test Blocked Entity', 'DEMO-WATCHLIST']] as [$n, $l]) {
                Db::exec('INSERT INTO watchlist(name,list_name) VALUES(?,?)', [$n, $l]);
            }
            Db::exec("INSERT INTO business_days(bdate,status,opened_at) VALUES(?, 'OPEN', ?)", [$startDate ?? date('Y-m-d'), time()]);

            // Opening capital: DR Cash in Vault, CR Share Capital
            Ledger::post('OPN', 'Opening capital', [
                ['gl' => '1010', 'dr' => 5_000_000_000, 'narr' => 'Opening vault cash'],
                ['gl' => '3000', 'cr' => 5_000_000_000, 'narr' => 'Share capital'],
            ]);
            Audit::log('system.install', 'database initialised');

            $adminId = Auth::createUser($admin['email'], $admin['name'], $admin['password'], 'supervisor', true, 3);
            Audit::log('staff.create', "user #{$adminId} supervisor (initial setup)", $adminId);
        });

        $creds = [];
        if ($demo) {
            $creds = self::seedDemo();
        }
        return $creds;
    }

    private static function seedDemo(): array
    {
        $creds = [];
        $mk = static function (string $email, string $name, string $role, int $tier) use (&$creds): int {
            $pw = self::randomPassword();
            $creds[$email] = $pw;
            return Auth::createUser($email, $name, $pw, $role, true, $tier);
        };
        $sup = $mk('supervisor@bank.test', 'Sam Supervisor', 'supervisor', 3);
        $mk('teller@bank.test', 'Tina Teller', 'teller', 3);
        $alice = $mk('alice@example.com', 'Alice Wanjiru', 'customer', 2);
        $bob = $mk('bob@example.com', 'Bob Otieno', 'customer', 2);

        $aDda = Accounts::open($alice, 'DDA');
        $aSav = Accounts::open($alice, 'SAV');
        $bDda = Accounts::open($bob, 'DDA');
        Teller::deposit($aDda['id'], 25_000_000, $sup, 'demo funding');
        Teller::deposit($aSav['id'], 10_000_000, $sup, 'demo funding');
        Teller::deposit($bDda['id'], 5_000_000, $sup, 'demo funding');
        return $creds;
    }
}