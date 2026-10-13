<?php

namespace Tests\Feature;

use App\Domain\Devconf\Encoding;
use App\Domain\Devconf\FormatRegistry;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Term;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\GroupService;
use App\Services\ImportService;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CampaignImportIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $participant;

    private User $validator;

    private MyabiVersion $version;

    private string $isolatedStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'myabi-campaign-test-'.bin2hex(random_bytes(8));
        mkdir($this->isolatedStorage.'/app/private', 0700, true);
        $this->app->useStoragePath($this->isolatedStorage);
        foreach (['manager', 'participant', 'validator'] as $actor) {
            $role = $actor === 'participant' ? 'translator' : $actor;
            $this->$actor = User::factory()->create(['roles' => [$role => ['fr']], 'organization_id' => null]);
        }
        User::factory()->create(['is_technical' => true, 'active' => false]);
        $this->version = MyabiVersion::create(['number' => 'Synthetic-2026', 'released_at' => '2026-10-01', 'status' => 'current', 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id]);
    }

    protected function tearDown(): void
    {
        if (isset($this->isolatedStorage) && dirname($this->isolatedStorage) === rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) && str_starts_with(basename($this->isolatedStorage), 'myabi-campaign-test-')) {
            File::deleteDirectory($this->isolatedStorage);
        }
        parent::tearDown();
    }

    private function import(array $rows, string $type = 'server'): ImportBatch
    {
        $format = FormatRegistry::get($type);
        $path = storage_path('app/private/input-'.bin2hex(random_bytes(8)).'.csv');
        $handle = fopen($path, 'wb');
        $write = fn (array $values) => fwrite($handle, Encoding::encode('"'.implode('";"', array_map(fn ($value) => str_replace('"', '""', $value), $values)).'"')."\r\n");
        $write($format->headers);
        foreach ($rows as $row) {
            $write(array_map(fn ($column) => $row[$column] ?? '', $format->headers));
        }
        fclose($handle);
        $service = app(ImportService::class);
        $import = $service->receive($path, $format->filename, ['type' => $type, 'version_id' => $this->version->id, 'organization_id' => null, 'source_kind' => 'publisher'], $this->manager);
        $service->analyze($import);
        $service->apply($import->fresh());

        return $import->fresh();
    }

    private function campaign(string $objective, array $terms)
    {
        return app(CampaignService::class)->create($this->manager, [
            'title' => 'Synthetic '.$objective, 'objective' => $objective, 'language' => 'fr',
            'assignee_ids' => [$this->participant->id], 'term_ids' => array_map(fn ($term) => $term->id, $terms),
        ]);
    }

    public function test_imported_pending_proposal_is_relevant_and_validation_confirms_its_campaign(): void
    {
        $this->import([['Name' => 'Synthetic.A', 'de_CH' => 'Reference A', 'fr_CH' => 'Translation A']]);
        $term = Term::firstOrFail();
        $proposal = $term->proposals()->where('language', 'fr')->firstOrFail();
        self::assertNotNull($proposal->context_fingerprint);
        $campaign = $this->campaign('translation', [$term]);
        $participation = $campaign->participations()->firstOrFail();
        self::assertSame($proposal->id, $participation->tracked_proposal_id);
        app(CampaignService::class)->start($this->manager, $campaign);
        app(WorkflowService::class)->decide($this->validator, $proposal, 'validate', $proposal->lock_version);
        self::assertSame('confirmed', $participation->fresh()->status);
        self::assertSame('completed', $campaign->fresh()->status);
        self::assertTrue($campaign->events()->where('action', 'confirmed_by_validation')->exists());
    }

    public function test_real_reimport_reexamines_open_confirmations_but_preserves_finished_history(): void
    {
        $this->import([
            ['Name' => 'Synthetic.A', 'de_CH' => 'Reference A', 'fr_CH' => 'Translation A'],
            ['Name' => 'Synthetic.B', 'de_CH' => 'Reference B', 'fr_CH' => 'Translation B'],
        ]);
        $terms = Term::orderBy('id')->get();
        foreach ($terms as $term) {
            $proposal = $term->proposals()->where('language', 'fr')->firstOrFail();
            app(WorkflowService::class)->decide($this->validator, $proposal, 'validate', $proposal->lock_version);
        }
        $term = $terms->first()->fresh();
        $open = $this->campaign('revision', $terms->all());
        $finished = $this->campaign('revision', [$term]);
        foreach ([$open, $finished] as $campaign) {
            app(CampaignService::class)->start($this->manager, $campaign);
            app(CampaignService::class)->confirm($this->participant, $campaign->fresh(), $term);
        }
        $this->import([
            ['Name' => 'Synthetic.A', 'de_CH' => 'Changed reference A', 'fr_CH' => 'Translation A'],
            ['Name' => 'Synthetic.B', 'de_CH' => 'Reference B', 'fr_CH' => 'Translation B'],
        ]);
        self::assertSame('to_process', $open->participations()->where('term_id', $term->id)->firstOrFail()->status);
        self::assertSame('confirmed', $finished->participations()->where('term_id', $term->id)->firstOrFail()->status);
        self::assertSame('completed', $finished->fresh()->status);
        self::assertTrue($open->events()->where('action', 'automatic_reexamination')->exists());
        self::assertTrue($finished->events()->where('action', 'confirmed_without_change')->exists());
    }

    public function test_source_change_detaches_stale_followed_proposal_without_cancelling_it(): void
    {
        $this->import([['Name' => 'Synthetic.A', 'de_CH' => 'Reference A', 'fr_CH' => 'Translation A']]);
        $term = Term::firstOrFail();
        $proposal = $term->proposals()->where('language', 'fr')->firstOrFail();
        $campaign = $this->campaign('translation', [$term]);
        app(CampaignService::class)->start($this->manager, $campaign);
        $this->import([['Name' => 'Synthetic.A', 'de_CH' => 'Changed reference', 'fr_CH' => 'Translation A']]);
        $participation = $campaign->participations()->firstOrFail();
        self::assertSame('to_process', $participation->status);
        self::assertNull($participation->tracked_proposal_id);
        self::assertSame('pending', $proposal->fresh()->status);
        self::assertFalse(app(CampaignService::class)->isRelevant($proposal->fresh(), $term->fresh()));
    }

    public function test_reimport_admits_group_member_when_its_target_cell_becomes_present(): void
    {
        $row = ['Form Type' => 'Synthetic', 'Form Template Name' => 'Generic', 'Control Name' => 'Label', 'Column Name' => 'Value',
            'Default Language' => 'de_CH', 'Default Label' => 'Reference', 'Language 1' => 'it_CH', 'Translation 1' => 'Italian value'];
        $this->import([$row], 'form');
        $term = Term::firstOrFail();
        $group = app(GroupService::class)->create($this->manager, ['title' => 'Synthetic forms']);
        app(GroupService::class)->assignTerms($this->manager, $group, [$term->id]);
        $campaign = app(CampaignService::class)->create($this->manager, ['title' => 'Waiting for target column', 'objective' => 'translation', 'language' => 'fr', 'group_ids' => [$group->id]]);
        self::assertSame(0, $campaign->metrics()['total']);
        self::assertSame(1, $campaign->admission_report['missing_column']);
        self::assertNull($campaign->first_admitted_at);
        $this->import([array_replace($row, ['Language 2' => 'fr_CH', 'Translation 2' => ''])], 'form');
        self::assertSame(1, $campaign->fresh()->metrics()['total']);
        self::assertNotNull($campaign->fresh()->first_admitted_at);
        self::assertSame('to_process', $campaign->participations()->firstOrFail()->status);
    }
}
