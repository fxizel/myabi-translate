<?php

namespace Tests\Feature;

use App\Domain\Devconf\TranslationValidator;
use App\Models\BulkOperation;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Publication;
use App\Models\User;
use App\Services\LocalizedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StoredMessageLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedStorage = storage_path('framework/testing/localized-messages-'.bin2hex(random_bytes(8)));
        $this->app->useStoragePath($this->isolatedStorage);
        foreach (['app/private', 'framework/views'] as $directory) {
            File::makeDirectory($this->isolatedStorage.'/'.$directory, 0700, true);
        }
        config(['view.compiled' => $this->isolatedStorage.'/framework/views', 'fortify.mfa_enabled' => false]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->isolatedStorage);
        parent::tearDown();
    }

    public function test_background_messages_and_old_german_diagnostics_follow_the_readers_language(): void
    {
        app()->setLocale('de');
        $keys = ['ui.analysis_stale', 'ui.publication_scope_unconfirmed'];
        $german = array_map(__(...), $keys);
        $stored = LocalizedMessage::store($german);
        $anomaly = __('ui.invalid_attribute');
        $encoding = __('translation_checks.encoding');
        $legacyEncoding = __('La proposition contient des caractères non exportables en Windows-1252.');

        foreach (['fr', 'it', 'de'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame(implode(' ', array_map(__(...), $keys)), LocalizedMessage::display($stored));
            $this->assertSame(implode(' ', array_map(__(...), $keys)), LocalizedMessage::display(implode(' ', $german)));
            $this->assertSame(__('ui.analysis_stale'), LocalizedMessage::display($german[0]));
            $this->assertSame(__('ui.invalid_attribute'), TranslationValidator::displayMessage($anomaly));
            $this->assertSame(__('ui.invalid_attribute'), TranslationValidator::displayMessage('ui.invalid_attribute'));
            $this->assertSame(__('translation_checks.encoding'), TranslationValidator::displayMessage($encoding));
            $this->assertSame(__('translation_checks.encoding'), TranslationValidator::displayMessage($legacyEncoding));
        }
        $unknown = '<script>unbekannter Fehler</script> — key=Original';
        $this->assertSame($unknown, LocalizedMessage::display(LocalizedMessage::store($unknown)));
        $this->assertSame($german[0].' '.$unknown, LocalizedMessage::display($german[0].' '.$unknown));
    }

    public function test_import_publication_and_bulk_error_views_localize_existing_messages_without_rewriting_them(): void
    {
        $organization = Organisation::create(['code' => 'VD', 'name' => 'Police VD', 'active' => true]);
        $manager = User::factory()->create(['locale' => 'fr', 'organization_id' => $organization->id, 'roles' => ['manager'], 'languages' => ['fr']]);
        $this->actingAs($manager);
        $version = MyabiVersion::create(['number' => '2026.2', 'released_at' => '2026-09-01', 'status' => 'current', 'created_by' => $manager->id, 'updated_by' => $manager->id]);
        $import = ImportBatch::create([
            'version_id' => $version->id, 'type' => 'server', 'format_version' => '1', 'source_kind' => 'publisher', 'is_complete' => true,
            'filename' => '03-DEVCONF-NLS-SERVER.csv', 'path' => 'unused.csv', 'sha256' => str_repeat('a', 64), 'size' => 0,
            'status' => 'failed', 'report' => ['duplicate_file' => false], 'error' => __('ui.analysis_stale', [], 'de'),
            'created_by' => $manager->id, 'updated_by' => $manager->id,
        ]);
        $operation = BulkOperation::create([
            'user_id' => $manager->id, 'import_id' => $import->id, 'language' => 'fr', 'status' => 'failed', 'expected_generation' => 0,
            'preview' => ['counts' => ['eligible' => 0, 'reasons' => []]], 'counts' => ['validated' => 0, 'excluded' => 0],
            'error' => __('ui.initial_failed', [], 'de'), 'created_by' => $manager->id, 'updated_by' => $manager->id,
        ]);
        $publication = Publication::create([
            'version_id' => $version->id, 'number' => '2026.2-01', 'status' => 'failed', 'types' => ['server'], 'recipients' => [$organization->id],
            'error' => __('ui.publication_failed', [], 'de'), 'created_by' => $manager->id, 'updated_by' => $manager->id,
        ]);

        $this->get('/imports/'.$import->id)->assertOk()
            ->assertSee(__('ui.analysis_stale', [], 'fr'))->assertDontSee($import->error)
            ->assertSee(__('ui.initial_failed', [], 'fr'))->assertDontSee($operation->error)
            ->assertSee('Fichier déjà importé')->assertSee('<td>Non</td>', false)->assertDontSee('duplicate file');
        $this->get('/imports/'.$import->id.'/progress')->assertOk()->assertJsonPath('error', __('ui.analysis_stale', [], 'fr'));
        $this->get('/publications/'.$publication->id)->assertOk()
            ->assertSee(__('ui.publication_failed', [], 'fr'))->assertDontSee($publication->error);
        $this->assertSame($import->error, $import->fresh()->error);
        $this->assertSame($operation->error, $operation->fresh()->error);
        $this->assertSame($publication->error, $publication->fresh()->error);

        $legacyError = __('ui.publication_source_partial', [], 'de');
        $publication->update(['error' => $legacyError]);
        $this->get('/publications/'.$publication->id)->assertOk()
            ->assertSee(__('ui.publication_legacy_rule', [], 'fr'))
            ->assertDontSee(__('ui.publication_source_partial', [], 'fr'));
        $this->assertSame($legacyError, $publication->fresh()->error);
        foreach (['fr', 'de', 'it'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame(__('ui.publication_legacy_rule'), LocalizedMessage::display(__('ui.publication_source_partial', [], $locale)));
            $this->assertSame(__('ui.publication_legacy_rule'), LocalizedMessage::display('ui.publication_source_partial'));
        }
        app()->setLocale('fr');

        $this->postJson('/publications', ['version_id' => $version->id, 'types' => ['server'], 'confirm' => 1])
            ->assertUnprocessable()->assertJsonPath('errors.source.0', 'Serveur — '.__('ui.publication_source_missing', [], 'fr'));

        $import->update(['error' => '<script>Original</script>']);
        $this->get('/imports/'.$import->id)->assertOk()->assertSee('&lt;script&gt;Original&lt;/script&gt;', false)->assertDontSee('<script>Original</script>', false);
    }
}
