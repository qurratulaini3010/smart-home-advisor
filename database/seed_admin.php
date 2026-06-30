<?php
declare(strict_types=1);

require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/core/Database.php';

$pdo = Database::connect();
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute(['admin@smarthome.local']);

if (!$stmt->fetch()) {
    $insert = $pdo->prepare('INSERT INTO users (full_name, email, password, phone, occupation, role) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([
        'System Administrator',
        'admin@smarthome.local',
        password_hash('Admin@123', PASSWORD_DEFAULT),
        '0123456789',
        'Smart Home Advisor Admin',
        'admin',
    ]);
}

echo "Admin account ready: admin@smarthome.local / Admin@123\n";

