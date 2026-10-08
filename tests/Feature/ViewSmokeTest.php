<?php

namespace Tests\Feature;

use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatRegistry;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Publication;
use App\Models\Term;
use App\Models\User;
use App\Services\ImportService;
use App\Services\InitialValidationService;
use App\Services\OperationLock;
use App\Services\SourceRecord;
use App\Services\StorageBudget;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ViewSmokeTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStorage;

    private User $manager;

    private MyabiVersion $version;

    private ImportBatch $import;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedStorage = storage_path('framework/testing/ui-'.bin2hex(random_bytes(8)));
        $this->app->useStoragePath($this->isolatedStorage);
        foreach (['app/private', 'framework/views', 'framework/cache', 'logs'] as $directory) {
            File::makeDirectory($this->isolatedStorage.'/'.$directory, 0700, true);
        }
        config(['view.compiled' => $this->isolatedStorage.'/framework/views', 'referentiel.storage_quota' => 1024 * 1024 * 1024]);
        $this->withoutMiddleware(RequireTwoFactor::class);
        $organization = Organisation::create(['code' => 'VD', 'name' => 'Police VD', 'active' => true]);
        $this->manager = User::factory()->create(['first_name' => 'Camille', 'last_name' => 'Martin', 'name' => 'Camille Martin', 'organization_id' => $organization->id, 'roles' => ['manager' => ['de', 'fr', 'it', 'en'], 'admin' => ['de', 'fr', 'it', 'en']], 'locale' => 'fr']);
        User::factory()->create(['name' => 'Import', 'email' => 'import@referentiel.invalid', 'is_technical' => true, 'active' => false]);
        $this->version = MyabiVersion::create(['number' => '2026.2', 'released_at' => '2026-09-01', 'status' => 'current', 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
        $format = FormatRegistry::get('server');
        $file = $this->isolatedStorage.'/app/private/source.csv';
        $handle = fopen($file, 'wb');
        foreach ([$format->headers, array_map(fn ($column) => ['Name' => 'ui.greeting', 'de_CH' => 'Hallo {name}', 'fr_CH' => 'Bonjour {name}', 'it_CH' => 'Ciao {name}'][$column] ?? '', $format->headers)] as $values) {
            fwrite($handle, Encoding::encode('"'.implode('";"', array_map(fn ($value) => str_replace('"', '""', $value), $values)).'"')."\r\n");
        }
        fclose($handle);
        $service = app(ImportService::class);
        $this->import = $service->receive($file, '03-DEVCONF-NLS-SERVER.csv', ['type' => 'server', 'version_id' => $this->version->id, 'source_kind' => 'publisher', 'organization_id' => null], $this->manager);
        $service->analyze($this->import);
        $service->apply($this->import->fresh());
        $this->import->refresh();
    }

    protected function tearDown(): void
    {
        if (isset($this->isolatedStorage)) {
            File::deleteDirectory($this->isolatedStorage);
        }
        parent::tearDown();
    }

    public function test_all_authorized_screens_render_with_real_imported_data(): void
    {
        $this->withoutExceptionHandling();
        $this->actingAs($this->manager);
        $term = Term::firstOrFail();
        foreach (['/', '/terms', '/terms?language=it&sort=key', '/terms?scope=own&sort=updated', '/terms/'.$term->id, '/terms/'.$term->id.'?from=1&to=1', '/validation', '/imports', '/imports/'.$this->import->id, '/publications', '/profile', '/admin', '/audit', '/help'] as $url) {
            $this->get($url)->assertOk()->assertSee('ARGE-ABI')->assertSee('main-content', false);
        }
    }

    public function test_myabi_navigation_only_links_to_the_users_authorized_sections(): void
    {
        $this->actingAs($this->manager);
        $navigation = '//nav[@aria-label="'.__('ui.main_navigation', [], 'fr').'"]';
        $workspace = '//nav[@aria-label="'.__('ui.workspace_navigation', [], 'fr').'"]';
        foreach (['terms', 'validation', 'imports', 'publications', 'admin', 'audit'] as $section) {
            $response = $this->get('/'.$section)->assertOk();
            $page = $this->pageXPath($response->getContent());
            $this->assertSame(1, $page->query('//main[@id="main-content"]')->length);
            $this->assertSame(1, $page->query('//a[@href="#main-content"]')->length);
            $this->assertSame(1, $page->query($workspace.'//*[@aria-current="page"]')->length);
            $this->assertSame(1, $page->query($navigation.'//*[@aria-current="page"]')->length);
            $this->assertSame(url($section), $page->query($navigation.'//*[@aria-current="page"]')->item(0)->getAttribute('href'));
            foreach (['terms', 'validation', 'imports', 'publications', 'admin', 'audit'] as $allowed) {
                $this->assertSame(1, $page->query($navigation.'//a[@href="'.url($allowed).'"]')->length);
            }
        }

        $this->manager->update(['roles' => ['reader' => ['de', 'fr', 'it', 'en']]]);
        foreach (['terms', 'publications'] as $section) {
            $page = $this->pageXPath($this->get('/'.$section)->assertOk()->getContent());
            foreach (['validation', 'imports', 'admin', 'audit'] as $restricted) {
                $this->assertSame(0, $page->query('//a[@href="'.url($restricted).'"]')->length);
            }
            foreach (['terms', 'publications'] as $allowed) {
                $this->assertSame(1, $page->query($navigation.'//a[@href="'.url($allowed).'"]')->length);
            }
            $this->assertSame(1, $page->query($workspace.'//*[@aria-current="page"]')->length);
            $this->assertSame(1, $page->query($navigation.'//*[@aria-current="page"]')->length);
        }
    }

    public function test_myabi_detail_tab_preserves_the_language_and_escapes_imported_titles(): void
    {
        $this->actingAs($this->manager);
        $term = Term::firstOrFail();
        $title = '<img src=x onerror="alert(1)"> & "titre"';
        $workspace = '//nav[@aria-label="'.__('ui.workspace_navigation', [], 'fr').'"]';
        $navigation = '//nav[@aria-label="'.__('ui.main_navigation', [], 'fr').'"]';
        foreach ([['source_text' => $title], ['source_text' => '', 'label' => $title]] as $values) {
            $term->update($values);
            $response = $this->get('/terms/'.$term->id.'?language=it')->assertOk()
                ->assertSee(e($title), false)->assertDontSee($title, false);
            $page = $this->pageXPath($response->getContent());
            $currentTabs = $page->query($workspace.'//*[@aria-current="page"]');
            $this->assertSame(1, $currentTabs->length);
            $currentTab = $currentTabs->item(0);
            $this->assertSame($title, $page->query('.//span[@class="tab-name"]', $currentTab)->item(0)->textContent);
            $this->assertSame(0, $page->query('.//img|.//script|.//*[@onerror]', $currentTab)->length);
            $closeLink = $page->query('.//a[@aria-label="'.__('ui.close_detail', [], 'fr').'"]', $currentTab);
            $this->assertSame(1, $closeLink->length);
            $this->assertSame(url('terms').'?language=it', $closeLink->item(0)->getAttribute('href'));
            $this->assertSame(1, $page->query($navigation.'//*[@aria-current="page"]')->length);
            $this->assertSame(url('terms'), $page->query($navigation.'//*[@aria-current="page"]')->item(0)->getAttribute('href'));
        }
    }

    private function pageXPath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    public function test_import_report_and_validation_preview_keep_the_operation_context_visible(): void
    {
        $this->withoutExceptionHandling();
        $this->actingAs($this->manager);
        $report = $this->get('/imports/'.$this->import->id)->assertOk();
        $page = $this->pageXPath($report->getContent());
        $this->assertSame(__('ui.imports').' / #'.$this->import->id, $page->evaluate('string(//div[@class="page-heading"]//p[@class="eyebrow"])'));

        $service = app(InitialValidationService::class);
        $analysis = $service->enqueuePreview($this->manager, $this->import, 'fr');
        $this->assertTrue(app(OperationLock::class)->run(fn () => $service->process($analysis), true));
        $response = $this->get('/imports/'.$this->import->id.'/validation-preview?language=fr&operation_id='.$analysis->id)->assertOk();
        $page = $this->pageXPath($response->getContent());
        $this->assertSame($this->import->filename.' / '.__('ui.fr'), $page->evaluate('string(//div[@class="page-heading"]//p[@class="eyebrow"])'));
    }

    public function test_translated_pages_and_proposal_controls_have_matching_server_contracts(): void
    {
        $this->withoutExceptionHandling();
        $this->actingAs($this->manager);
        $term = Term::firstOrFail();
        $this->get('/validation')->assertOk()->assertSee('name="confirmed"', false)->assertSee('name="selection[', false)->assertSee('name="override"', false);
        $this->get('/terms/'.$term->id)->assertSee('name="lock_version"', false)->assertSee('name="reason_category"', false)->assertSee('Corriger la proposition');
        foreach (['de' => 'Übersetzungen', 'fr' => 'Traductions', 'it' => 'Traduzioni'] as $locale => $heading) {
            $this->manager->update(['locale' => $locale]);
            $this->get('/terms')->assertOk()->assertSee($heading);
        }
        $this->post('/locale', ['locale' => 'de'])->assertRedirect();
        $this->assertSame('de', $this->manager->fresh()->locale);
    }

    public function test_publication_manifest_and_profile_enrollment_render(): void
    {
        config(['fortify.mfa_enabled' => true]);
        $this->withoutExceptionHandling();
        $this->actingAs($this->manager);
        $recipient = ['organization_id' => $this->manager->organization_id, 'name' => 'Police VD', 'code' => 'VD', 'files' => [['name' => '03-DEVCONF-NLS-SERVER.csv', 'type' => 'server', 'records' => 1, 'format_version' => '1', 'sha256' => str_repeat('a', 64), 'coverage' => ['fr' => ['validated' => 0, 'fallback' => 1]]]], 'archive' => ['name' => '2026.2-01-VD.zip', 'bytes' => 100, 'sha256' => str_repeat('b', 64), 'path' => 'VD.zip']];
        $publication = Publication::create(['version_id' => $this->version->id, 'number' => '2026.2-01', 'status' => 'published', 'types' => ['server'], 'recipients' => [$this->manager->organization_id], 'manifest' => ['recipients' => [$this->manager->organization_id => $recipient]], 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
        $response = $this->get('/publications/'.$publication->id)->assertOk()->assertSee('Police VD')->assertSee('2026.2-01-VD.zip');
        $page = $this->pageXPath($response->getContent());
        $this->assertSame(__('ui.publication_number').' / myABI '.$this->version->number, $page->evaluate('string(//div[@class="page-heading"]//p[@class="eyebrow"])'));
        $this->manager->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => null])->save();
        $this->get('/profile')->assertOk()->assertSee('name="code"', false)->assertSee('JBSWY3DPEHPK3PXP');
    }

    public function test_imported_content_is_escaped_in_html(): void
    {
        $this->actingAs($this->manager);
        $this->mock(SourceRecord::class)->shouldReceive('attributes')->andReturn([
            'label' => ['reference' => '<script>alert("xss")</script>', 'translations' => [], 'columns' => ['fr' => 'fr_CH']],
        ]);
        $response = $this->get('/terms');
        $response->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("xss")</script>', false);
    }

    public function test_guest_authentication_views_render(): void
    {
        $this->withoutExceptionHandling();
        foreach (['/login', '/forgot-password', '/reset-password/token?email=person@example.test'] as $url) {
            $this->get($url)->assertOk()->assertSee('name="_token"', false);
        }
    }

    public function test_a_rejected_write_retains_the_unsent_draft_for_the_matching_term(): void
    {
        $this->actingAs($this->manager);
        $term = Term::firstOrFail();
        Proposal::where('term_id', $term->id)->update(['status' => 'rejected', 'rejection_reason' => 'Test']);
        app(OperationLock::class)->run(function () use ($term) {
            $this->from('/terms')->post('/terms/'.$term->id.'/proposals', [
                'term_id' => $term->id, 'attribute' => 'label', 'language' => 'fr',
                'value' => 'Une nouvelle valeur {name}', 'lock_version' => $term->lock_version,
            ])->assertRedirect('/terms')->assertSessionHasInput('value', 'Une nouvelle valeur {name}');
        }, true);
        $this->get('/terms')->assertOk()->assertSee('Une nouvelle valeur {name}')->assertSee('data-restored="1"', false);
        $this->from('/terms')->post('/proposals/bulk-store', [
            'confirmed' => 1, 'rows' => [$term->id => ['selected' => 1, 'attribute' => 'label', 'language' => 'fr', 'value' => 'Conserver ma saisie', 'lock_version' => $term->lock_version]],
        ])->assertRedirect('/terms')->assertSessionHasErrors();
        $this->get('/terms')->assertOk()->assertSee('Conserver ma saisie')->assertSee('data-restored="1"', false);
    }

    public function test_rejected_correction_and_revision_restore_keep_their_lineage(): void
    {
        $this->withoutExceptionHandling();
        $this->actingAs($this->manager);
        $term = Term::firstOrFail();
        $proposal = Proposal::where('language', 'fr')->firstOrFail();
        $workflow = app(WorkflowService::class);
        $workflow->decide($this->manager, $proposal, 'reject', $proposal->lock_version, 'Orthographe');
        $this->get('/terms/'.$term->id)->assertOk()->assertSee('name="supersedes_id"', false)->assertSee('Corriger ce rejet');
        $next = $workflow->propose($this->manager, $term, 'label', 'fr', 'Une valeur {name}', $term->lock_version);
        $workflow->decide($this->manager, $next, 'validate', $next->lock_version, null, true);
        $this->get('/terms/'.$term->id.'?restore_revision=2')->assertOk()->assertSee('name="restored_revision_id"', false)->assertSee('Une valeur {name}')->assertSee('valeurs de la révision 2');
    }

    public function test_zero_is_displayed_as_a_translation_value_and_a_pending_proposal(): void
    {
        $this->actingAs($this->manager);
        $term = Term::firstOrFail();
        $term->update(['validated' => ['label' => ['fr' => '0']]]);
        Proposal::where('term_id', $term->id)->where('language', 'fr')->update(['value' => '0']);
        $this->get('/terms')->assertOk()->assertDontSee('id="proposal-'.$term->id.'"', false);
        $this->get('/terms/'.$term->id)->assertOk()->assertSee('<p class="translation-text">0</p>', false);
    }

    public function test_storage_gauge_warns_at_eighty_percent_in_each_interface_language(): void
    {
        $budget = \Mockery::mock(StorageBudget::class);
        $budget->shouldReceive('usage')->once()->andReturn(['bytes' => 790 * 1048576, 'quota' => 1000 * 1048576, 'ratio' => 0.79, 'warning' => false]);
        $budget->shouldReceive('usage')->times(3)->andReturn(['bytes' => 800 * 1048576, 'quota' => 1000 * 1048576, 'ratio' => 0.8, 'warning' => true]);
        $this->app->instance(StorageBudget::class, $budget);
        $this->actingAs($this->manager)->get('/imports')->assertOk()->assertSee('<meter', false)->assertSee('79.0 %')->assertDontSee('id="storage-warning"', false);
        foreach (['fr' => 'Au moins 80 %', 'de' => 'Mindestens 80 %', 'it' => 'Almeno l’80 %'] as $locale => $warning) {
            $this->manager->update(['locale' => $locale]);
            $this->get('/imports')->assertOk()->assertSee($warning)->assertSee('id="storage-warning"', false)->assertSee('800.0')->assertSee('1’000.0');
        }
    }

    public function test_placeholder_errors_and_stored_anomalies_follow_the_interface_language(): void
    {
        $this->actingAs($this->manager);
        $term = Term::firstOrFail();
        Proposal::where('term_id', $term->id)->update(['anomalies' => ['Les placeholders et leur nombre doivent être identiques à la référence.', 'identical']]);
        foreach (['de' => 'Die Platzhalter und ihre Anzahl müssen mit der Referenz übereinstimmen.', 'it' => 'Le variabili e il loro numero devono essere identici al riferimento.'] as $locale => $message) {
            $this->manager->update(['locale' => $locale]);
            $this->followingRedirects()->from('/terms')->post('/terms/'.$term->id.'/proposals', ['term_id' => $term->id, 'attribute' => 'label', 'language' => 'fr', 'value' => 'Variable absente', 'lock_version' => $term->lock_version])
                ->assertOk()->assertSee($message)->assertDontSee('Les placeholders');
            $this->get('/validation')->assertOk()->assertSee($message)->assertSee(__('translation_checks.identical'))->assertDontSee('Les placeholders');
            $this->get('/terms/'.$term->id)->assertOk()->assertSee($message)->assertSee(__('translation_checks.identical'))->assertDontSee('Les placeholders');
        }
    }
}
