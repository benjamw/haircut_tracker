<?php

declare(strict_types=1);

exit(0); // DISABLED

/**
 * Create (or reset the password of) an admin account — the safe way to set up
 * the barber's login without baking a password into the repo.
 *
 *   php bin/create_admin.php <username> <password>
 *
 * If the username exists, its password is reset and role set to admin; otherwise
 * a new person + admin user is created.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Support\Dotenv;

Dotenv::load(__DIR__ . '/../.env');

$username = strtolower(trim((string) ($argv[1] ?? '')));
$password = (string) ($argv[2] ?? '');

if (strlen($username) < 3 || strlen($password) < 8) {
    fwrite(STDERR, "usage: php bin/create_admin.php <username> <password>\n");
    fwrite(STDERR, "  username: >= 3 chars; password: >= 8 chars\n");
    exit(1);
}

$db = Database::connect();
$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $db->prepare('SELECT user_id FROM users WHERE username = ?');
$stmt->execute([$username]);
$existing = $stmt->fetch();

if ($existing) {
    $db->prepare("UPDATE users SET password_hash = ?, role = 'admin', status = 'active' WHERE user_id = ?")
        ->execute([$hash, (int) $existing['user_id']]);
    fwrite(STDOUT, "Updated admin '{$username}' (password reset, role=admin).\n");
} else {
    $db->prepare(
        "INSERT INTO users (display_name, username, password_hash, role, status)
         VALUES (?, ?, ?, 'admin', 'active')"
    )->execute([ucfirst($username), $username, $hash]);
    fwrite(STDOUT, "Created admin '{$username}'.\n");
}
