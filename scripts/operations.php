<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Operational read-only metadata and health checks. Never print configuration secrets.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$mode = $argv[1] ?? 'health';
$database = DB::connection();
if ($mode === 'quota') {
    $bytes = (int) $database->selectOne('SELECT COALESCE(SUM(data_length + index_length),0) bytes FROM information_schema.tables WHERE table_schema = DATABASE()')->bytes;
    echo json_encode(['database_bytes' => $bytes, 'quota' => config('referentiel.storage_quota'), 'external_bytes' => config('referentiel.external_storage_used'), 'url' => config('app.url'), 'database' => $database->getDatabaseName()]);
    exit(0);
}
if ($mode === 'database-name') {
    echo $database->getDatabaseName();
    exit(0);
}
$database->select('SELECT 1');
foreach ([storage_path('app/private'), storage_path('framework/cache'), storage_path('framework/views'), base_path('bootstrap/cache')] as $path) {
    if (! is_dir($path) || ! is_writable($path)) {
        throw new RuntimeException('Required runtime directory is not writable: '.$path);
    }
}
$migrator = $app->make('migrator');
$pending = array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan());
if ($pending !== []) {
    throw new RuntimeException('Database migrations are pending.');
}
echo "Database, schema and private runtime directories are healthy.\n";
