<?php

use Illuminate\Contracts\Console\Kernel;

// Development benchmark bootstrap: exported synthetic settings only; never load the application's .env.
require_once dirname(__DIR__, 2).'/vendor/autoload.php';
if (getenv('APP_ENV') !== 'testing' || getenv('MYABI_ISOLATED_TESTS') !== '1'
    || ! preg_match('/\Amyabi_test_[a-z0-9_]+\z/', getenv('DB_DATABASE') ?: '')
    || ! in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)
    || ! getenv('MYABI_BENCHMARK_DATADIR') || ! getenv('APP_KEY')) {
    throw new RuntimeException('Explicit isolated database, datadir and synthetic APP_KEY settings are required.');
}
$directory = dirname(__DIR__, 2).'/.runtime/recherche-approximative';
foreach (['app/private', 'framework/views', 'framework/cache', 'framework/sessions', 'logs'] as $suffix) {
    if (! is_dir($directory.'/storage/'.$suffix)) {
        mkdir($directory.'/storage/'.$suffix, 0700, true);
    }
}
putenv('APP_CONFIG_CACHE='.$directory.'/unused-config.php');
putenv('LARAVEL_STORAGE_PATH='.$directory.'/storage');
putenv('DB_CONNECTION=mariadb');
putenv('DB_URL=');
putenv('MAIL_MAILER=array');
putenv('CACHE_STORE=array');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath($directory);
$app->loadEnvironmentFrom('unused-environment.env');
$app->make(Kernel::class)->bootstrap();

return $app;
