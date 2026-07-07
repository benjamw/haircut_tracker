<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal .env loader for hosts that don't allow real environment variables
 * (typical shared hosting). Parses KEY=VALUE lines and exposes them via
 * putenv()/$_ENV so the existing Env helper (getenv) keeps working unchanged.
 *
 * Real environment variables always win — so Docker/compose (which sets env
 * directly) is unaffected, and a missing file is a harmless no-op.
 */
final class Dotenv
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));

            // Strip matching surrounding quotes.
            if (strlen($val) >= 2
                && (($val[0] === '"' && $val[-1] === '"') || ($val[0] === "'" && $val[-1] === "'"))) {
                $val = substr($val, 1, -1);
            }

            if ($key === '' || getenv($key) !== false) {
                continue; // don't override a real environment variable
            }

            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}
