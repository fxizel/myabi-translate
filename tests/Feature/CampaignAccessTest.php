<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\TermGroup;
use App\Models\TranslationCampaign;
use App\Models\User;
use App\Services\SourceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CampaignAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Organisation $organization;

    private string $isolatedStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedStorage = storage_path('framework/testing/campaign-access-'.bin2hex(random_bytes(8)));
        $this->app->useStoragePath($this->isolatedStorage);
        foreach (['app/private', 'framework/views', 'framework/cache', 'logs'] as $directory) {
            File::makeDirectory($this->isolatedStorage.'/'.$directory, 0700, true);
        }
        config(['view.compiled' => $this->isolatedStorage.'/framework/views']);
        $this->withoutMiddleware(RequireTwoFactor::class);
        $this->organization = Organisation::create(['code' => 'QA', 'name' => 'Synthetic campaign organization', 'active' => true]);
        $this->manager = $this->user(['manager' => ['de', 'fr', 'it', 'en']]);
        $source = $this->mock(SourceRecord::class);
        $source->shouldReceive('attributes')->andReturn([
            'label' => ['reference' => 'Synthetic reference', 'reference_language' => 'de',
                'translations' => ['de' => 'Synthetic reference', 'fr' => '', 'it' => '', 'en' => ''],
                'columns' => ['de' => 'de_CH', 'fr' => 'fr_CH', 'it' => 'it_CH', 'en' => 'en_US']],
        ]);
        $source->shouldReceive('row')->andReturnUsing(fn (Term $term) => ['Name' => $term->key[0], 'de_CH' => $term->source_text]);
        $this->actingAs($this->manager);
    }

    protected function tearDown(): void
    {
        if (isset($this->isolatedStorage)) {
            File::deleteDirectory($this->isolatedStorage);
        }
        parent::tearDown();
    }

    public function test_group_and_campaign_pages_require_an_authenticated_session(): void
    {
        $this->app['auth']->logout();

        foreach (['/groups', '/groups/create', '/campaigns', '/campaigns/create'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    public function test_admin_role_alone_does_not_allow_management_or_campaign_work(): void
    {
        $term = $this->term('Admin restriction', ['fr' => 'Current value']);
        $campaign = $this->campaign('Managed campaign', ['objective' => 'revision', 'term_ids' => [$term->id], 'assignee_ids' => [$this->manager->id]]);
        $this->post('/campaigns/'.$campaign->id.'/start')->assertRedirect()->assertSessionHasNoErrors();
        $admin = $this->user(['admin' => ['de', 'fr', 'it', 'en']]);
        $this->actingAs($admin);

        $this->postJson('/groups', ['title' => 'Forbidden group', 'color' => '#2266a4'])->assertForbidden();
        $this->postJson('/campaigns', ['title' => 'Forbidden campaign', 'objective' => 'translation', 'language' => 'fr'])->assertForbidden();
        $this->postJson('/groups/assign', ['group_id' => null, 'term_ids' => [$term->id]])->assertForbidden();
        $this->postJson('/campaigns/'.$campaign->id.'/terms/'.$term->id.'/confirm', ['lock_version' => $term->lock_version])->assertForbidden();

        self::assertFalse(TermGroup::where('title', 'Forbidden group')->exists());
        self::assertFalse(TranslationCampaign::where('title', 'Forbidden campaign')->exists());
        self::assertSame(['label' => ['fr' => 'Current value']], $term->fresh()->validated);
        self::assertSame(0, Proposal::count());
    }

    public function test_unassigned_translator_cannot_submit_confirm_or_request_arbitration(): void
    {
        $assigned = $this->user(['translator' => ['fr']]);
        $outsider = $this->user(['translator' => ['fr']]);
        $term = $this->term('Assignment restriction', ['fr' => 'Current value']);
        $campaign = $this->campaign('Assigned campaign', ['objective' => 'revision', 'term_ids' => [$term->id], 'assignee_ids' => [$assigned->id]]);
        $this->post('/campaigns/'.$campaign->id.'/start')->assertRedirect()->assertSessionHasNoErrors();
        $before = $campaign->participations()->firstOrFail()->getAttributes();
        $this->actingAs($outsider);

        $base = '/campaigns/'.$campaign->id.'/terms/'.$term->id;
        $this->postJson($base.'/propose', ['value' => 'Unauthorized correction', 'lock_version' => $term->lock_version])->assertForbidden();
        $this->postJson($base.'/confirm', ['lock_version' => $term->lock_version])->assertForbidden();
        $this->postJson($base.'/arbitration', ['reason' => 'Unauthorized arbitration'])->assertForbidden();

        self::assertSame(0, Proposal::count());
        self::assertSame(['label' => ['fr' => 'Current value']], $term->fresh()->validated);
        self::assertSame($before, $campaign->participations()->firstOrFail()->getAttributes());
    }

    public function test_assignment_does_not_preserve_a_language_right_that_was_revoked(): void
    {
        $participant = $this->user(['translator' => ['fr']]);
        $term = $this->term('Language restriction', ['fr' => 'Current value']);
        $campaign = $this->campaign('Language campaign', ['objective' => 'revision', 'term_ids' => [$term->id], 'assignee_ids' => [$participant->id]]);
        $this->post('/campaigns/'.$campaign->id.'/start')->assertRedirect()->assertSessionHasNoErrors();
        $before = $campaign->participations()->firstOrFail()->getAttributes();
        $participant->update(['roles' => ['translator' => ['it']]]);
        $this->actingAs($participant);

        $base = '/campaigns/'.$campaign->id.'/terms/'.$term->id;
        $this->postJson($base.'/propose', ['value' => 'Wrong language', 'lock_version' => $term->lock_version])->assertForbidden();
        $this->postJson($base.'/confirm', ['lock_version' => $term->lock_version])->assertForbidden();
        $this->postJson($base.'/arbitration', ['reason' => 'Wrong language arbitration'])->assertForbidden();

        self::assertSame(0, Proposal::count());
        self::assertSame($before, $campaign->participations()->firstOrFail()->getAttributes());
        self::assertSame(['label' => ['fr' => 'Current value']], $term->fresh()->validated);
    }

    public function test_assignment_does_not_grant_access_when_the_term_moves_to_a_private_scope(): void
    {
        $participant = $this->user(['translator' => ['fr']]);
        $term = $this->term('Scope restriction', ['fr' => 'Current value'], $this->organization->id);
        $campaign = $this->campaign('Scope campaign', ['objective' => 'revision', 'term_ids' => [$term->id], 'assignee_ids' => [$participant->id]]);
        $this->post('/campaigns/'.$campaign->id.'/start')->assertRedirect()->assertSessionHasNoErrors();
        $before = $campaign->participations()->firstOrFail()->getAttributes();
        $foreign = Organisation::create(['code' => 'QB', 'name' => 'Synthetic foreign organization', 'active' => true]);
        $term->update(['organization_id' => $foreign->id, 'lock_version' => $term->lock_version + 1]);
        $this->actingAs($participant);

        $base = '/campaigns/'.$campaign->id.'/terms/'.$term->id;
        $this->get('/campaigns/'.$campaign->id)->assertNotFound();
        $this->postJson($base.'/propose', ['value' => 'Foreign correction', 'lock_version' => $term->lock_version])->assertForbidden();
        $this->postJson($base.'/confirm', ['lock_version' => $term->lock_version])->assertForbidden();

        self::assertSame(0, Proposal::count());
        self::assertSame($before, $campaign->participations()->firstOrFail()->getAttributes());
    }

    public function test_assigned_participant_can_confirm_without_acquiring_validation_rights(): void
    {
        $participant = $this->user(['translator' => ['fr']]);
        $term = $this->term('Independent confirmation', ['fr' => 'Current value']);
        $term->update(['review_needed' => ['label' => ['fr' => true]]]);
        $campaign = $this->campaign('Confirmation campaign', ['objective' => 'revision', 'term_ids' => [$term->id], 'assignee_ids' => [$participant->id]]);
        $this->post('/campaigns/'.$campaign->id.'/start')->assertRedirect()->assertSessionHasNoErrors();
        $before = $term->fresh()->getAttributes();
        $this->actingAs($participant);

        $this->post('/campaigns/'.$campaign->id.'/terms/'.$term->id.'/confirm', ['lock_version' => $term->lock_version])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson('/terms/'.$term->id.'/confirm', ['attribute' => 'label', 'language' => 'fr', 'lock_version' => $term->lock_version])->assertForbidden();

        self::assertSame($before, $term->fresh()->getAttributes());
        self::assertSame(0, Proposal::count());
        self::assertSame('completed', $campaign->fresh()->status);
    }

    public function test_catalogue_group_and_ungrouped_filters_preserve_term_visibility_and_totals(): void
    {
        $group = $this->group('Catalogue grouping');
        $grouped = $this->term('Grouped visible');
        $ungrouped = $this->term('Ungrouped visible');
        $foreign = Organisation::create(['code' => 'QB', 'name' => 'Synthetic foreign organization', 'active' => true]);
        $private = $this->term('Grouped private', owner: $foreign->id);
        $this->post('/groups/assign', ['group_id' => $group->id, 'term_ids' => [$grouped->id]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $reader = $this->user(['reader' => ['fr']]);
        $this->actingAs($reader);

        $filtered = $this->get('/terms?group_id='.$group->id)->assertOk();
        self::assertSame([$grouped->id], $filtered->viewData('terms')->pluck('id')->all());
        self::assertSame(1, $filtered->viewData('terms')->total());
        self::assertSame(['server' => 1], $filtered->viewData('typeCounts')->all());
        $without = $this->get('/terms?group_id=none')->assertOk();
        self::assertSame([$ungrouped->id], $without->viewData('terms')->pluck('id')->all());
        self::assertSame(1, $without->viewData('terms')->total());
        $filtered->assertDontSee('Grouped private');
    }

    public function test_private_memberships_hide_group_and_campaign_instructions_as_a_whole(): void
    {
        $foreign = Organisation::create(['code' => 'QB', 'name' => 'Synthetic foreign organization', 'active' => true]);
        $private = $this->term('Private campaign term', owner: $foreign->id);
        $visible = $this->term('Common campaign term');
        $group = $this->group('Private membership grouping');
        $this->post('/groups/assign', ['group_id' => $group->id, 'term_ids' => [$private->id, $visible->id]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $campaign = $this->campaign('Private membership campaign', ['description' => 'Confidential synthetic instructions',
            'group_ids' => [$group->id], 'assignee_ids' => [$this->manager->id]]);
        $this->actingAs($this->user(['reader' => ['fr']]));

        $this->get('/groups')->assertOk()->assertDontSee($group->title);
        $this->get('/campaigns')->assertOk()->assertDontSee($campaign->title)->assertDontSee('Confidential synthetic instructions');
        $this->get('/groups/'.$group->id)->assertNotFound();
        $this->get('/campaigns/'.$campaign->id)->assertNotFound();
        $this->get('/terms')->assertOk()->assertSee($visible->label)->assertDontSee($private->label);
    }

    public function test_translator_cannot_reclassify_a_term(): void
    {
        $group = $this->group('Protected grouping');
        $term = $this->term('Protected membership');
        $this->post('/groups/assign', ['group_id' => $group->id, 'term_ids' => [$term->id]])->assertRedirect()->assertSessionHasNoErrors();
        $before = $term->fresh()->getAttributes();
        $this->actingAs($this->user(['translator' => ['fr']]));

        $this->postJson('/groups/assign', ['group_id' => null, 'term_ids' => [$term->id]])->assertForbidden();

        self::assertSame($before, $term->fresh()->getAttributes());
    }

    public function test_kanban_filters_select_campaigns_without_confusing_assignment_and_language(): void
    {
        $participant = $this->user(['translator' => ['fr', 'it']]);
        $group = $this->group('Filter grouping');
        $target = $this->campaign('Needle target', ['language' => 'fr', 'objective' => 'translation',
            'deadline' => now()->subDay()->format('Y-m-d'), 'group_ids' => [$group->id], 'assignee_ids' => [$participant->id]]);
        $other = $this->campaign('Other Italian review', ['language' => 'it', 'objective' => 'revision',
            'deadline' => now()->addDay()->format('Y-m-d'), 'assignee_ids' => [$this->manager->id]]);
        foreach ([['q' => 'Needle'], ['language' => 'fr'], ['objective' => 'translation'], ['group_id' => $group->id],
            ['assignee_id' => $participant->id], ['overdue' => 1]] as $filter) {
            $this->get('/campaigns?'.http_build_query($filter))->assertOk()->assertSee($target->title)->assertDontSee($other->title);
        }
        $this->actingAs($participant);
        $this->get('/campaigns?mine=1')->assertOk()->assertSee($target->title)->assertDontSee($other->title);
    }

    public function test_archived_campaign_keeps_its_details_and_leaves_the_current_kanban(): void
    {
        $term = $this->term('Archive confirmation', ['fr' => 'Current value']);
        $campaign = $this->campaign('Archive campaign', ['objective' => 'revision', 'description' => 'Preserved archive instructions',
            'term_ids' => [$term->id], 'assignee_ids' => [$this->manager->id]]);
        $this->post('/campaigns/'.$campaign->id.'/start')->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/campaigns/'.$campaign->id.'/terms/'.$term->id.'/confirm', ['lock_version' => $term->lock_version])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/campaigns/'.$campaign->id.'/archive')->assertRedirect()->assertSessionHasNoErrors();

        $this->get('/campaigns')->assertOk()->assertDontSee($campaign->title);
        $this->get('/campaigns?archived=1')->assertOk()->assertSee($campaign->title);
        $this->get('/campaigns/'.$campaign->id)->assertOk()->assertSee('Preserved archive instructions')->assertSee($term->label);
        self::assertSame('completed', $campaign->fresh()->status);
        self::assertSame(1, $campaign->participations()->count());
    }

    public function test_removing_the_last_assignee_after_start_is_rejected_without_partial_changes(): void
    {
        $participant = $this->user(['translator' => ['fr']]);
        $term = $this->term('Last assignment', ['fr' => 'Current value']);
        $campaign = $this->campaign('Preserved assignment campaign', ['objective' => 'revision',
            'term_ids' => [$term->id], 'assignee_ids' => [$participant->id]]);
        $this->post('/campaigns/'.$campaign->id.'/start')->assertRedirect()->assertSessionHasNoErrors();

        $this->patchJson('/campaigns/'.$campaign->id, ['title' => 'Partial update must roll back',
            'language' => 'fr', 'objective' => 'revision', 'assignee_ids' => []])->assertUnprocessable();

        self::assertSame('Preserved assignment campaign', $campaign->fresh()->title);
        self::assertSame('in_progress', $campaign->fresh()->status);
        self::assertSame([$participant->id], $campaign->assignees()->pluck('users.id')->all());
        $this->actingAs($participant)->post('/campaigns/'.$campaign->id.'/terms/'.$term->id.'/confirm', ['lock_version' => $term->lock_version])
            ->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('completed', $campaign->fresh()->status);
    }

    public function test_management_screens_render_in_each_supported_interface_language_and_escape_instructions(): void
    {
        $group = $this->group('Localized group');
        $term = $this->term('Localized term', ['fr' => 'Current value']);
        $instructions = '<img src=x onerror="alert(1)"> Synthetic instructions';
        $campaign = $this->campaign('Localized campaign', ['objective' => 'revision', 'description' => $instructions,
            'term_ids' => [$term->id], 'assignee_ids' => [$this->manager->id]]);
        foreach (['de', 'fr', 'it'] as $locale) {
            $this->manager->update(['locale' => $locale]);
            foreach (['/groups', '/groups/create', '/groups/'.$group->id, '/groups/'.$group->id.'/edit',
                '/campaigns', '/campaigns/create', '/campaigns/'.$campaign->id, '/campaigns/'.$campaign->id.'/edit'] as $path) {
                $this->get($path)->assertOk()->assertSee('lang="'.$locale.'"', false)->assertSee('main-content', false)
                    ->assertDontSee('campaigns.', false)->assertDontSee('groups.', false);
            }
            $this->get('/campaigns/'.$campaign->id)->assertOk()->assertSee(e($instructions), false)->assertDontSee($instructions, false);
        }
    }

    private function user(array $roles, ?int $organizationId = null): User
    {
        return User::factory()->create(['organization_id' => $organizationId ?? $this->organization->id,
            'roles' => $roles, 'locale' => 'fr']);
    }

    private function group(string $title): TermGroup
    {
        $this->actingAs($this->manager)->post('/groups', ['title' => $title, 'color' => '#2266a4'])
            ->assertRedirect()->assertSessionHasNoErrors();

        return TermGroup::where('title', $title)->firstOrFail();
    }

    private function campaign(string $title, array $data = []): TranslationCampaign
    {
        $this->actingAs($this->manager)->post('/campaigns', $data + ['title' => $title, 'language' => 'fr', 'objective' => 'translation'])
            ->assertRedirect()->assertSessionHasNoErrors();

        return TranslationCampaign::where('title', $title)->firstOrFail();
    }

    private function term(string $label, array $translations = [], ?int $owner = null): Term
    {
        return Term::create(['type' => 'server', 'organization_id' => $owner, 'identity_hash' => hash('sha256', $label),
            'key' => [$label], 'label' => $label, 'context' => 'Synthetic context', 'source_text' => 'Synthetic reference',
            'search_text' => $label.' Synthetic reference', 'semantic_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64),
            'source_import_id' => 1, 'source_offset' => 0, 'source_length' => 1,
            'validated' => $translations ? ['label' => $translations] : [], 'review_needed' => [],
            'created_by' => $this->manager->id, 'updated_by' => $this->manager->id])->fresh();
    }
}
