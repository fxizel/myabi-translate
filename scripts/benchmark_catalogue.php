<?php

declare(strict_types=1);

use App\Http\Controllers\TermController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;

// Read-only catalogue/controller/Blade timings; no institutional values printed.
// php scripts/benchmark_catalogue.php [--email=local-manager@example.test] [--reader] [--include-states]
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local', 'testing')) {
    fwrite(STDERR, "This measurement is restricted to local/test environments.\n");
    exit(2);
}
$options = getopt('', ['email:', 'reader', 'include-states']);
$user = User::where('email', $options['email'] ?? 'benchmark@referentiel.invalid')->firstOrFail();
if (array_key_exists('reader', $options)) {
    // Simulate visibility on this in-memory instance only; never save a role.
    $user->roles = ['reader'];
}
DB::disableQueryLog();
$app['auth']->setUser($user);
$session = $app['session']->driver();
$session->start();
view()->share('errors', new ViewErrorBag);
$cases = ['catalogue' => [], 'prefix' => ['q' => 'Code', 'search_mode' => 'prefix'],
    'text' => ['q' => 'police'], 'short_word' => ['q' => 'de'], 'no_result' => ['q' => 'inexistantzzzzzzzzz']];
if (array_key_exists('include-states', $options)) {
    foreach (['missing', 'validated', 'review', 'published', 'obsolete', 'pending'] as $state) {
        $cases[$state] = ['state' => $state];
    }
}
foreach ($cases as $name => $query) {
    $request = Request::create('/terms', 'GET', $query);
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession($session);
    $app->instance('request', $request);
    $started = microtime(true);
    $view = app(TermController::class)->index($request);
    $data = $view->getData();
    $html = $view->render();
    echo json_encode(['case' => $name, 'database' => DB::connection()->getDatabaseName(),
        'scope' => array_key_exists('reader', $options) ? 'reader' : 'account',
        'seconds' => round(microtime(true) - $started, 3), 'rows' => $data['terms']->count(), 'total' => $data['terms']->total(),
        'html_bytes' => strlen($html), 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR).PHP_EOL;
}
