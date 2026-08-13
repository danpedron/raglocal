<?php
declare(strict_types=1);
[$dsn,$user,$pass,$email,$adminPass,$adminName] = array_pad(array_slice($argv, 1), 6, '');
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stmt = $pdo->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role=VALUES(role)');
$adminName = trim((string) $adminName) ?: 'Administrador';
$stmt->execute([$adminName, $email, password_hash($adminPass, PASSWORD_DEFAULT), 'admin']);
echo "admin-created\n";
