<?php
declare(strict_types=1);

namespace Bank;

final class Config
{
    private static ?array $cfg = null;

    public static function all(): array
    {
        if (self::$cfg === null) {
            $cfg = require dirname(__DIR__) . '/config/config.php';
            $local = dirname(__DIR__) . '/config/local.php';
            if (is_file($local)) {
                $cfg = array_replace_recursive($cfg, require $local);
            }
            $extra = getenv('BANK_CONFIG');          // optional extra override file (tests, containers)
            if ($extra && is_file($extra)) {
                $cfg = array_replace_recursive($cfg, require $extra);
            }
            self::$cfg = $cfg;
        }
        return self::$cfg;
    }

    /** Dotted-path lookup: Config::get('fraud.weights.velocity') */
    public static function get(string $path, mixed $default = null): mixed
    {
        $node = self::all();
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /** Test helper. */
    public static function override(string $path, mixed $value): void
    {
        $cfg = self::all();
        $ref = &$cfg;
        foreach (explode('.', $path) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = $ref[$part] ?? [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
        self::$cfg = $cfg;
    }

    /** Daily transfer limit for a KYC tier, in minor units. */
    public static function dailyLimit(int $tier): int
    {
        return (int) (self::get('daily_limits.' . $tier, 0)) * 100;
    }
}
