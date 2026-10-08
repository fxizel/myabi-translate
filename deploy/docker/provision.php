<?php

// Explicit administrative one-shot. Root credentials are never mounted in the web service.
require '/var/www/html/vendor/autoload.php';
$env = Dotenv\Dotenv::parse(file_get_contents('/var/www/html/.env'));
$secret = static function (string $name): string {
    $value = rtrim(file_get_contents('/run/secrets/'.$name), "\r\n");
    if (strlen($value) < 20) {
        throw new RuntimeException('A database secret must contain at least 20 characters.');
    }

    return $value;
};
$appPassword = $env['DB_PASSWORD'] ?? '';
if (strlen($appPassword) < 20) {
    throw new RuntimeException('Configure the application database password first.');
}
$pdo = new PDO('mysql:host=db;dbname=myabi;charset=utf8mb4', 'root', $secret('db_root_password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['myabi_app' => $appPassword, 'myabi_migrate' => $secret('db_migration_password'), 'myabi_backup' => $secret('db_backup_password')] as $user => $password) {
    $account = $pdo->quote($user)."@'%'";
    $pdo->exec('CREATE USER IF NOT EXISTS '.$account.' IDENTIFIED BY '.$pdo->quote($password));
    // Re-running provisioning deliberately reconciles the three account passwords.
    $pdo->exec('ALTER USER '.$account.' IDENTIFIED BY '.$pdo->quote($password));
}
$pdo->exec("GRANT ALL PRIVILEGES ON `myabi`.* TO 'myabi_migrate'@'%'");
$pdo->exec("GRANT SELECT, SHOW VIEW, TRIGGER ON `myabi`.* TO 'myabi_backup'@'%'");
$pdo->exec("GRANT SELECT ON `mysql`.`proc` TO 'myabi_backup'@'%'");
$tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'myabi' AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    if (! preg_match('/^[a-z0-9_]+$/D', $table)) {
        throw new RuntimeException('Unexpected table identifier.');
    }
    $permissions = $table === 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE';
    $pdo->exec("GRANT $permissions ON `myabi`.`$table` TO 'myabi_app'@'%'");
}
echo 'Dedicated database accounts provisioned; application grants applied to '.count($tables)." existing tables.\n";
