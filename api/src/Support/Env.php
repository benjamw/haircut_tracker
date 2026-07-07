<?php

declare(strict_types=1);

namespace App\Support;

final class Env
{
    public static function get(string $key, string $default = ''): string
    {
        $v = getenv($key);
        return $v === false || $v === '' ? $default : $v;
    }

    public static function int(string $key, int $default): int
    {
        $v = getenv($key);
        return $v === false || $v === '' ? $default : (int) $v;
    }
}
