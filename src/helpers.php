<?php
declare(strict_types=1);

/** HTML-escape for templates. */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Format minor units for display. */
function money(int $minor): string
{
    return Bank\Money::fmt($minor);
}

function b64(string $bin): string
{
    return base64_encode($bin);
}

function unb64(string $s): string
{
    $r = base64_decode($s, true);
    if ($r === false) {
        throw new RuntimeException('Invalid base64');
    }
    return $r;
}
