<?php
declare(strict_types=1);

[$dsn, $user, $pass, $email, $adminPass, $adminName] = array_pad(array_slice($argv, 1), 6, '');
$email = strtolower(trim((string) $email));
$adminName = trim((string) $adminName) ?: 'Administrador';
$generatedPassword = trim((string) $adminPass) === '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php bin/bootstrap_admin.php DSN DB_USER DB_PASS ADMIN_EMAIL [ADMIN_PASSWORD] [ADMIN_NAME]\n");
    exit(2);
}

if ($generatedPassword) {
    $adminPass = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
} elseif (strlen((string) $adminPass) < 12 || strlen((string) $adminPass) > 255) {
    fwrite(STDERR, "A senha informada deve ter entre 12 e 255 caracteres.\n");
    exit(2);
}

$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stmt = $pdo->prepare('INSERT INTO users(name,email,password_hash,role,must_change_password) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), role=VALUES(role), must_change_password=VALUES(must_change_password)');
$stmt->execute([$adminName, $email, password_hash((string) $adminPass, PASSWORD_DEFAULT), 'admin', $generatedPassword ? 1 : 0]);

echo "admin-created\n";
echo "email={$email}\n";
if ($generatedPassword) {
    echo "temporary-password={$adminPass}\n";
    echo "must-change-password=1\n";
    echo "Guarde essa senha temporaria e altere-a no primeiro acesso. Ela nao sera exibida novamente.\n";
}
