<?php

use Illuminate\Contracts\Console\Kernel;

// A separate short-lived process gets the migration credential, never a cached web config.
$password = rtrim(file_get_contents('/run/secrets/db_migration_password'), "\r\n");
if (strlen($password) < 20) {
    throw new RuntimeException('Configure the migration secret first.');
}
foreach (['DB_USERNAME' => 'myabi_migrate', 'DB_PASSWORD' => $password] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$status = $kernel->call('migrate', ['--force' => true, '--no-interaction' => true]);
echo $kernel->output();
exit($status);
