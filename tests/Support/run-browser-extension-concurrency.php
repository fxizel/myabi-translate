<?php

/** Create a fresh disposable schema on the already-running portable test instance. */
$project = dirname(__DIR__, 2);
$credentialsFile = $project.'/.runtime/mariadb-tests/connection.json';
$schema = 'myabi_test_extension_concurrency_'.bin2hex(random_bytes(6));
$pdo = null;
$schemaCreated = false;
try {
    if (getenv('MYABI_ISOLATED_TESTS') !== '1' || ! is_file($credentialsFile)) {
        throw new RuntimeException('Run with MYABI_ISOLATED_TESTS=1 and the portable instance available.');
    }
    $config = json_decode(file_get_contents($credentialsFile), true, 8, JSON_THROW_ON_ERROR);
    $normalize = static fn (string $path) => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    $root = $normalize($project.'/.runtime/mariadb-tests').'/';
    if (! in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true)
        || ! is_int($config['port']) || $config['port'] < 1 || $config['port'] > 65535
        || ! str_starts_with($normalize($config['datadir']).'/', $root)
        || ! preg_match('/\Amyabi_test_[a-z0-9_]+\z/', $schema)) {
        throw new RuntimeException('The isolated instance verification failed.');
    }
    $pdo = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $actual = $pdo->query('SELECT @@datadir AS datadir, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
    if ($normalize($actual['datadir']) !== $normalize($config['datadir']) || ! str_contains($actual['version'], 'MariaDB')) {
        throw new RuntimeException('The isolated instance verification failed.');
    }
    $pdo->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $schemaCreated = true;
    $directory = $project.'/.runtime/mariadb-tests/concurrency-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $environment = getenv();
    $environment = array_replace($environment, [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_NAME' => 'Synthetic concurrency tests',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_URL' => 'http://127.0.0.1',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
        'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_DATABASE' => $schema, 'DB_URL' => '',
        'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4',
        'MYABI_ISOLATED_TESTS' => '1', 'MYABI_CONCURRENCY_EXPECTED_DATADIR' => $config['datadir'],
        'MYABI_CONCURRENCY_DIRECTORY' => $directory,
    ]);
    $process = proc_open([PHP_BINARY, $project.'/vendor/bin/phpunit', '--configuration', $project.'/phpunit.xml',
        '--colors=never', $project.'/tests/Integration/BrowserExtensionConcurrencyTest.php',
    ], [0 => ['pipe', 'r'], 1 => ['file', $directory.'/phpunit.log', 'w'], 2 => ['file', $directory.'/phpunit-errors.log', 'w']], $pipes, $project, $environment, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('The test process could not start.');
    }
    fclose($pipes[0]);
    $exitCode = proc_close($process);
    // Keep complete diagnostic traces private; stdout contains only test statistics.
    foreach (explode("\n", file_get_contents($directory.'/phpunit.log')) as $line) {
        if (preg_match('/\A(?:PHPUnit |Time: |OK|FAILURES!|ERRORS!|Tests: |There (?:was|were) )/', $line)) {
            echo $line, PHP_EOL;
        }
    }
    if ($exitCode !== 0) {
        fwrite(STDERR, "Concurrency diagnostics are saved in the private runtime directory.\n");
    }
} catch (Throwable) {
    fwrite(STDERR, "The isolated concurrency runner could not complete. No connection details were printed.\n");
    $exitCode = 1;
} finally {
    if ($pdo && $schemaCreated) {
        // The name is generated here, never read from the credentials or the application configuration.
        try {
            $pdo->exec('DROP DATABASE IF EXISTS `'.$schema.'`');
        } catch (Throwable) {
            fwrite(STDERR, "The disposable test schema could not be cleaned up.\n");
            $exitCode = 1;
        }
    }
}
exit($exitCode);
