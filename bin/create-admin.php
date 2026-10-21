<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Core/Env.php';
require BASE_PATH . '/app/Core/Database.php';
\App\Core\Env::load(BASE_PATH . '/.env');

if ($argc !== 3 || !filter_var($argv[2], FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/create-admin.php \"Full Name\" admin@example.com\n");
    exit(2);
}
$name = trim($argv[1]);
if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
    fwrite(STDERR, "Name must be between 2 and 150 characters.\n");
    exit(2);
}
$db = \App\Core\Database::connection();
$existing = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ((int) $existing > 0) {
    fwrite(STDERR, "An admin already exists. Use the approved account recovery process.\n");
    exit(1);
}
$email = strtolower($argv[2]);
$password = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$stmt = $db->prepare("INSERT INTO users (full_name, email, password_hash, role, email_verified_at, status) VALUES (?, ?, ?, 'admin', UTC_TIMESTAMP(), 'active')");
$stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
$adminId = (int) $db->lastInsertId();
$audit = $db->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, metadata) VALUES (?, 'admin.provisioned', 'user', ?, JSON_OBJECT('method', 'cli'))");
$audit->execute([$adminId, $adminId]);
fwrite(STDOUT, "Admin account created. Save this one-time password securely; it will not be shown again:\n{$password}\n");
