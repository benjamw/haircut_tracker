<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Thin PDO factory. Reads connection settings from the environment
 * (see .env.example / docker-compose.yml).
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        if (self::$pdo === null) {
            $host = getenv('DB_HOST') ?: 'db';
            $port = getenv('DB_PORT') ?: '3306';
            $name = getenv('DB_NAME') ?: 'haircut_tracker';
            $user = getenv('DB_USER') ?: 'haircut';
            $pass = getenv('DB_PASSWORD') ?: 'haircut';

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // Pin the DB session to the shop's current UTC offset so MySQL NOW()
            // agrees with PHP (which uses SHOP_TZ). Critical on shared hosting
            // where the DB server clock is usually UTC. Offset form works without
            // MySQL's named-timezone tables being loaded.
            $tz = getenv('SHOP_TZ') ?: 'America/Denver';
            try {
                $offset = (new \DateTime('now', new \DateTimeZone($tz)))->format('P');
                self::$pdo->exec("SET time_zone = '{$offset}'");
            } catch (\Throwable $e) {
                // Bad SHOP_TZ -> leave the server default rather than crash.
            }
        }

        return self::$pdo;
    }
}
