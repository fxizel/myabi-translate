<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenancePageTest extends TestCase
{
    public function test_maintenance_serves_the_shared_html_page_and_preserves_json_responses(): void
    {
        $storage = sys_get_temp_dir().'/myabi-maintenance-'.Str::uuid();
        File::ensureDirectoryExists($storage.'/framework');
        $this->app->useStoragePath($storage);

        try {
            $this->artisan('down', ['--retry' => 60])->assertSuccessful();

            $this->get('/login', ['Accept' => 'text/html'])
                ->assertStatus(503)
                ->assertHeader('Retry-After', '60')
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertContent(file_get_contents(resource_path('views/errors/maintenance.html')));

            $this->getJson('/login')->assertStatus(503)->assertHeader('Retry-After', '60');
            $this->get('/api/browser-extension/search', ['Accept' => 'text/html'])
                ->assertStatus(503)
                ->assertHeader('Retry-After', '60')
                ->assertJsonPath('error.code', 'search_index_unavailable');

            $this->artisan('up')->assertSuccessful();
            $this->get('/login')->assertOk();
        } finally {
            File::deleteDirectory($storage);
        }
    }

    public function test_an_unrelated_service_unavailable_error_keeps_its_own_page(): void
    {
        Route::get('/test-unavailable', fn () => abort(503));

        $this->get('/test-unavailable')
            ->assertStatus(503)
            ->assertDontSee('Service temporairement indisponible');
    }
}
