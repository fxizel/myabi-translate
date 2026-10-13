<?php

namespace Tests\Feature;

use App\Domain\Devconf\FormatRegistry;
use App\Models\CampaignParticipation;
use App\Models\Proposal;
use App\Models\Revision;
use App\Models\Term;
use App\Models\TermState;
use App\Models\TranslationCampaign;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\GroupService;
use App\Services\SourceRecord;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TranslationCampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $participant;

    private User $validator;

    private int $sequence = 0;

    private array $columns = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['roles' => ['manager'], 'locale' => 'fr']);
        $this->participant = User::factory()->create(['roles' => ['translator' => ['fr']], 'locale' => 'fr']);
        $this->validator = User::factory()->create(['roles' => ['validator' => ['fr']], 'locale' => 'fr']);
        $source = $this->mock(SourceRecord::class);
        $source->shouldReceive('attributes')->andReturnUsing(fn (Term $term) => [
            'label' => ['reference' => $term->source_text, 'translations' => [], 'columns' => $this->columns[$term->id] ?? ['fr' => 'fr_CH', 'it' => 'it_CH']],
        ]);
        $source->shouldReceive('row')->andReturnUsing(fn (Term $term) => array_replace(array_fill_keys(FormatRegistry::get('server')->headers, ''), ['Name' => $term->key[0], 'de_CH' => $term->source_text]));
        $source->shouldReceive('snapshot')->andReturnUsing(fn (Term $term, string $origin, int $originId, int $authorId) => Revision::create([
            'term_id' => $term->id, 'number' => $term->revision_no, 'version_id' => 1,
            'source_import_id' => 1, 'source_offset' => 0, 'source_length' => 1,
            'validated' => $term->validated, 'metadata' => [], 'origin' => $origin,
            'origin_id' => $originId, 'created_by' => $authorId, 'created_at' => now(),
        ]));
    }

    private function service(): CampaignService
    {
        return app(CampaignService::class);
    }

    private function term(?string $value = null): Term
    {
        $key = 'synthetic.'.++$this->sequence;

        return Term::create([
            'type' => 'server', 'organization_id' => null, 'identity_hash' => hash('sha256', $key),
            'raw_identity_hash' => hash('sha256', $key), 'key' => [$key], 'label' => $key,
            'context' => '', 'source_text' => 'Reference', 'search_text' => $key,
            'semantic_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64),
            'source_import_id' => 1, 'source_offset' => 0, 'source_length' => 1,
            'validated' => $value === null ? [] : ['label' => ['fr' => $value]],
            'review_needed' => [], 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id,
        ])->fresh();
    }

    private function campaign(string $objective = 'translation', array $terms = []): TranslationCampaign
    {
        $campaign = $this->service()->create($this->manager, [
            'title' => 'Synthetic campaign '.++$this->sequence, 'objective' => $objective, 'language' => 'fr',
            'assignee_ids' => [$this->participant->id],
        ]);
        if ($terms) {
            $this->service()->addDirectTerms($this->manager, $campaign, array_map(fn ($term) => $term->id, $terms));
        }

        return $campaign->fresh();
    }

    private function participation(TranslationCampaign $campaign, Term $term): CampaignParticipation
    {
        return $campaign->participations()->where('term_id', $term->id)->firstOrFail();
    }

    private function pending(Term $term, string $value = 'Translation'): Proposal
    {
        return app(WorkflowService::class)->propose($this->participant, $term->fresh(), 'label', 'fr', $value, $term->fresh()->lock_version);
    }

    private function refuses(callable $action): void
    {
        try {
            $action();
            self::fail('The invalid transition must be rejected.');
        } catch (ValidationException $exception) {
            self::assertNotEmpty($exception->errors());
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_admission_distinguishes_absent_columns_from_empty_and_zero_values(): void
    {
        $missing = $this->term();
        $empty = $this->term('');
        $zero = $this->term('0');
        $absent = $this->term();
        $this->columns[$absent->id] = ['it' => 'it_CH'];
        $translation = $this->campaign('translation', [$missing, $empty, $zero, $absent]);
        $revision = $this->campaign('revision', [$missing, $empty, $zero, $absent]);
        self::assertSame([$missing->id], $translation->participations()->where('active', true)->pluck('term_id')->all());
        self::assertSame([$empty->id, $zero->id], $revision->participations()->where('active', true)->orderBy('term_id')->pluck('term_id')->all());
        self::assertFalse($translation->participations()->where('term_id', $absent->id)->exists());
    }

    public function test_language_and_objective_lock_survives_removal_of_all_terms(): void
    {
        $term = $this->term();
        $campaign = $this->campaign();
        $this->service()->update($this->manager, $campaign, ['title' => 'Editable', 'language' => 'it', 'objective' => 'revision']);
        $this->service()->update($this->manager, $campaign->fresh(), ['title' => 'Editable', 'language' => 'fr', 'objective' => 'translation']);
        $this->service()->addDirectTerms($this->manager, $campaign->fresh(), [$term->id]);
        $this->service()->removeDirectTerm($this->manager, $campaign->fresh(), $term);
        self::assertNotNull($campaign->fresh()->first_admitted_at);
        $this->refuses(fn () => $this->service()->update($this->manager, $campaign->fresh(), ['title' => 'Locked', 'language' => 'it', 'objective' => 'translation']));
        $this->refuses(fn () => $this->service()->update($this->manager, $campaign->fresh(), ['title' => 'Locked', 'language' => 'fr', 'objective' => 'revision']));
    }

    public function test_first_admission_tracks_one_relevant_proposal_and_arbitrates_competing_proposals(): void
    {
        $single = $this->term();
        $pending = $this->pending($single);
        $competing = $this->term();
        $this->pending($competing, 'First choice');
        $this->pending($competing, 'Second choice');
        $campaign = $this->campaign('translation', [$single, $competing]);
        self::assertSame('proposal_sent', $this->participation($campaign, $single)->status);
        self::assertSame($pending->id, $this->participation($campaign, $single)->tracked_proposal_id);
        $arbitration = $this->participation($campaign, $competing);
        self::assertSame('to_arbitrate', $arbitration->status);
        self::assertNull($arbitration->tracked_proposal_id);
        self::assertNotEmpty($arbitration->reason);
    }

    public function test_returning_participation_never_automatically_tracks_an_old_pending_proposal(): void
    {
        $term = $this->term();
        $proposal = $this->pending($term);
        $campaign = $this->campaign('translation', [$term]);
        $this->service()->removeDirectTerm($this->manager, $campaign, $term);
        self::assertFalse($this->participation($campaign, $term)->active);
        $this->service()->addDirectTerms($this->manager, $campaign->fresh(), [$term->id]);
        $participation = $this->participation($campaign, $term);
        self::assertTrue($participation->active);
        self::assertSame('to_process', $participation->status);
        self::assertNull($participation->tracked_proposal_id);
        self::assertSame('pending', $proposal->fresh()->status);
    }

    public function test_returning_participant_cannot_choose_a_new_proposal_over_an_existing_pending_one(): void
    {
        $term = $this->term();
        $proposal = $this->pending($term);
        $campaign = $this->campaign('translation', [$term]);
        $this->service()->start($this->manager, $campaign);
        $this->service()->removeDirectTerm($this->manager, $campaign->fresh(), $term);
        $this->service()->addDirectTerms($this->manager, $campaign->fresh(), [$term->id]);
        $this->refuses(fn () => $this->service()->propose($this->participant, $campaign->fresh(), $term, 'Competing value'));
        self::assertSame(1, $term->proposals()->count());
        self::assertSame('to_process', $this->participation($campaign, $term)->status);
        $this->service()->attachProposal($this->participant, $campaign->fresh(), $term, $proposal);
        self::assertSame($proposal->id, $this->participation($campaign, $term)->tracked_proposal_id);
        self::assertSame('pending', $proposal->fresh()->status);
    }

    public function test_changed_reference_prevents_automatic_attachment_of_stale_proposals(): void
    {
        $term = $this->term();
        $proposal = $this->pending($term);
        $term->update(['source_text' => 'Different reference', 'source_hash' => str_repeat('d', 64)]);
        $campaign = $this->campaign('translation', [$term->fresh()]);
        self::assertSame('to_process', $this->participation($campaign, $term)->status);
        self::assertNull($this->participation($campaign, $term)->tracked_proposal_id);
        self::assertSame('pending', $proposal->fresh()->status);
    }

    public function test_rejection_updates_only_campaigns_that_still_track_the_proposal(): void
    {
        $term = $this->term('Correct current value');
        $proposal = $this->pending($term, 'Correction');
        $first = $this->campaign('revision', [$term, $this->term('Another current value')]);
        $second = $this->campaign('revision', [$term]);
        $this->service()->start($this->manager, $first);
        $this->service()->start($this->manager, $second);
        $before = $term->fresh()->validated;
        $revision = $term->revision_no;
        $this->service()->confirm($this->participant, $first->fresh(), $term->fresh());
        self::assertNull($this->participation($first, $term)->tracked_proposal_id);
        self::assertSame('pending', $proposal->fresh()->status);
        self::assertSame($before, $term->fresh()->validated);
        self::assertSame($revision, $term->fresh()->revision_no);
        app(WorkflowService::class)->decide($this->validator, $proposal->fresh(), 'reject', $proposal->fresh()->lock_version, 'Meaning needs correction');
        self::assertSame('confirmed', $this->participation($first, $term)->status);
        self::assertSame('to_process', $this->participation($second, $term)->status);
        self::assertStringContainsString('Meaning needs correction', $this->participation($second, $term)->reason);
    }

    public function test_external_validation_reexamines_open_confirmation_but_keeps_completed_campaign_frozen(): void
    {
        $term = $this->term('Current');
        $proposal = $this->pending($term, 'Updated');
        $open = $this->campaign('revision', [$term, $this->term('Other')]);
        $finished = $this->campaign('revision', [$term]);
        foreach ([$open, $finished] as $campaign) {
            $this->service()->start($this->manager, $campaign);
            $this->service()->confirm($this->participant, $campaign->fresh(), $term->fresh());
        }
        self::assertSame('completed', $finished->fresh()->status);
        app(WorkflowService::class)->decide($this->validator, $proposal->fresh(), 'validate', $proposal->fresh()->lock_version);
        self::assertSame('to_process', $this->participation($open, $term)->status);
        self::assertSame('confirmed', $this->participation($finished, $term)->status);
        self::assertSame('completed', $finished->fresh()->status);
    }

    public function test_validation_confirms_all_active_links_and_rejects_links_to_superseded_choices(): void
    {
        $term = $this->term();
        $selected = $this->pending($term, 'Chosen value');
        $first = $this->campaign('translation', [$term]);
        $second = $this->campaign('translation', [$term]);
        foreach ([$first, $second] as $campaign) {
            $this->service()->start($this->manager, $campaign);
        }
        $competitor = $this->pending($term, 'Other choice');
        $third = $this->campaign('translation', [$term]);
        $this->service()->start($this->manager, $third);
        $this->service()->resolveArbitration($this->validator, $third->fresh(), $term, 'proposal_sent', 'Choose the other proposition for review', $competitor->id);
        app(WorkflowService::class)->decide($this->validator, $selected->fresh(), 'validate', $selected->fresh()->lock_version);
        foreach ([$first, $second] as $campaign) {
            self::assertSame('confirmed', $this->participation($campaign, $term)->status);
            self::assertSame('completed', $campaign->fresh()->status);
        }
        self::assertSame('to_process', $this->participation($third, $term)->status);
        self::assertSame('in_progress', $third->fresh()->status);
        self::assertSame('rejected', $competitor->fresh()->status);
    }

    public function test_all_proposals_sent_do_not_complete_the_campaign_and_empty_started_campaign_stays_open(): void
    {
        $term = $this->term();
        $campaign = $this->campaign('translation', [$term]);
        $this->service()->start($this->manager, $campaign);
        $this->service()->propose($this->participant, $campaign->fresh(), $term, 'Proposed');
        self::assertSame('proposal_sent', $this->participation($campaign, $term)->status);
        self::assertSame('in_progress', $campaign->fresh()->status);
        $this->service()->removeDirectTerm($this->manager, $campaign->fresh(), $term);
        self::assertSame(0, $campaign->participations()->where('active', true)->count());
        self::assertSame('in_progress', $campaign->fresh()->status);
    }

    public function test_source_change_reexamines_confirmations_only_in_open_campaigns(): void
    {
        $term = $this->term('Current');
        $open = $this->campaign('revision', [$term, $this->term('Other')]);
        $finished = $this->campaign('revision', [$term]);
        foreach ([$open, $finished] as $campaign) {
            $this->service()->start($this->manager, $campaign);
            $this->service()->confirm($this->participant, $campaign->fresh(), $term->fresh());
        }
        $term->refresh()->update(['source_text' => 'Changed reference', 'source_hash' => str_repeat('c', 64)]);
        self::assertSame('to_process', $this->participation($open, $term)->status);
        self::assertSame('confirmed', $this->participation($finished, $term)->status);
    }

    public function test_other_language_and_source_metadata_changes_do_not_cancel_primary_confirmation(): void
    {
        $term = $this->term('Current');
        $campaign = $this->campaign('revision', [$term, $this->term('Other')]);
        $this->service()->start($this->manager, $campaign);
        $this->service()->confirm($this->participant, $campaign->fresh(), $term);
        $term->refresh()->update(['validated' => ['label' => ['fr' => 'Current', 'it' => 'Changed Italian']], 'source_hash' => str_repeat('d', 64)]);
        self::assertSame('confirmed', $this->participation($campaign, $term)->status);
        self::assertSame('in_progress', $campaign->fresh()->status);
    }

    public function test_group_has_one_membership_and_cannot_be_deleted_while_used(): void
    {
        $groups = app(GroupService::class);
        $first = $groups->create($this->manager, ['title' => 'First subject']);
        $second = $groups->create($this->manager, ['title' => 'Second subject']);
        $term = $this->term();
        $identity = $term->identity_hash;
        $groups->assignTerms($this->manager, $first, [$term->id]);
        $groups->assignTerms($this->manager, $second, [$term->id]);
        self::assertSame($second->id, $term->fresh()->term_group_id);
        self::assertSame($identity, $term->fresh()->identity_hash);
        self::assertSame([], $term->fresh()->validated);
        self::assertSame(0, $first->terms()->count());
        $this->refuses(fn () => $groups->delete($this->manager, $second));
        $groups->assignTerms($this->manager, null, [$term->id]);
        $campaign = $this->service()->create($this->manager, ['title' => 'Empty linked', 'objective' => 'translation', 'language' => 'fr', 'group_ids' => [$second->id]]);
        $this->refuses(fn () => $groups->delete($this->manager, $second));
        $groups->archive($this->manager, $second);
        self::assertNotNull($second->fresh()->archived_at);
        self::assertTrue($campaign->groups()->whereKey($second->id)->exists());
    }

    public function test_prepared_campaign_cannot_be_worked_and_start_requires_terms_and_assignments(): void
    {
        $empty = $this->campaign();
        $this->refuses(fn () => $this->service()->start($this->manager, $empty));
        $term = $this->term('Current');
        $campaign = $this->campaign('revision', [$term]);
        try {
            $this->service()->confirm($this->participant, $campaign, $term);
            self::fail('Work before start must be refused.');
        } catch (HttpException $exception) {
            self::assertContains($exception->getStatusCode(), [403, 422]);
        } catch (ValidationException $exception) {
            self::assertNotEmpty($exception->errors());
        }
        $campaign->assignees()->detach();
        $this->refuses(fn () => $this->service()->start($this->manager, $campaign->fresh()));
    }

    public function test_group_synchronization_removes_only_indirect_terms_and_returns_them_to_process(): void
    {
        $groups = app(GroupService::class);
        $group = $groups->create($this->manager, ['title' => 'Synthetic subject']);
        $indirect = $this->term();
        $direct = $this->term();
        $groups->assignTerms($this->manager, $group, [$indirect->id, $direct->id]);
        $campaign = $this->service()->create($this->manager, [
            'title' => 'Synchronized', 'language' => 'fr', 'objective' => 'translation',
            'group_ids' => [$group->id], 'term_ids' => [$direct->id], 'assignee_ids' => [$this->participant->id],
        ]);
        self::assertSame(2, $campaign->metrics()['total']);
        $this->service()->start($this->manager, $campaign);
        $this->service()->propose($this->participant, $campaign->fresh(), $indirect, 'Still pending');
        $groups->assignTerms($this->manager, null, [$indirect->id, $direct->id]);
        self::assertFalse($this->participation($campaign, $indirect)->active);
        self::assertTrue($this->participation($campaign, $direct)->active);
        self::assertSame(1, $campaign->fresh()->metrics()['total']);
        $groups->assignTerms($this->manager, $group, [$indirect->id]);
        self::assertSame('to_process', $this->participation($campaign, $indirect)->status);
        self::assertNull($this->participation($campaign, $indirect)->tracked_proposal_id);
        self::assertSame(1, $indirect->proposals()->where('status', 'pending')->count());
        self::assertGreaterThan(0, $campaign->events()->where('campaign_participation_id', $this->participation($campaign, $indirect)->id)->count());
    }

    public function test_completed_group_campaign_is_frozen_and_reexamination_pins_term_before_sync(): void
    {
        $groups = app(GroupService::class);
        $group = $groups->create($this->manager, ['title' => 'Completed subject']);
        $term = $this->term('Current');
        $groups->assignTerms($this->manager, $group, [$term->id]);
        $campaign = $this->service()->create($this->manager, [
            'title' => 'Completed campaign', 'language' => 'fr', 'objective' => 'revision',
            'group_ids' => [$group->id], 'assignee_ids' => [$this->participant->id],
        ]);
        $this->service()->start($this->manager, $campaign);
        $this->service()->confirm($this->participant, $campaign->fresh(), $term);
        $groups->assignTerms($this->manager, null, [$term->id]);
        $new = $this->term('New current');
        $groups->assignTerms($this->manager, $group, [$new->id]);
        self::assertSame('completed', $campaign->fresh()->status);
        self::assertSame([$term->id], $campaign->participations()->where('active', true)->pluck('term_id')->all());
        $this->service()->reexamine($this->validator, $campaign->fresh(), $term, 'Source requires another examination');
        self::assertSame('in_progress', $campaign->fresh()->status);
        self::assertTrue($this->participation($campaign, $term)->direct);
        self::assertTrue($this->participation($campaign, $term)->active);
        self::assertSame('to_process', $this->participation($campaign, $term)->status);
        self::assertTrue($this->participation($campaign, $new)->active);
    }

    public function test_excluded_selection_does_not_lock_a_never_admitted_campaign(): void
    {
        $term = $this->term();
        $this->columns[$term->id] = ['it' => 'it_CH'];
        $campaign = $this->campaign();
        $report = $this->service()->addDirectTerms($this->manager, $campaign, [$term->id]);
        self::assertSame(1, $report['missing_column']);
        self::assertNull($campaign->fresh()->first_admitted_at);
        self::assertSame(0, $campaign->fresh()->metrics()['total']);
        self::assertNull($campaign->fresh()->metrics()['work_progress']);
        $this->service()->update($this->manager, $campaign->fresh(), ['title' => 'New language', 'language' => 'it', 'objective' => 'translation']);
        self::assertSame('it', $campaign->fresh()->language);
    }

    public function test_arbitration_requires_reason_and_its_resolution_preserves_the_validation_circuit(): void
    {
        $term = $this->term();
        $campaign = $this->campaign('translation', [$term]);
        $this->service()->start($this->manager, $campaign);
        $this->refuses(fn () => $this->service()->requestArbitration($this->participant, $campaign->fresh(), $term, ' '));
        $this->service()->requestArbitration($this->participant, $campaign->fresh(), $term, 'Context insufficient');
        self::assertSame('to_arbitrate', $this->participation($campaign, $term)->status);
        $proposal = $this->pending($term);
        $this->service()->resolveArbitration($this->validator, $campaign->fresh(), $term, 'proposal_sent', 'Relevant choice', $proposal->id);
        self::assertSame('pending', $proposal->fresh()->status);
        self::assertSame([], $term->fresh()->validated);
        self::assertSame('proposal_sent', $this->participation($campaign, $term)->status);
    }

    public function test_archival_and_deletion_preserve_campaign_and_group_history(): void
    {
        $empty = $this->campaign();
        $this->service()->delete($this->manager, $empty);
        self::assertNull(TranslationCampaign::find($empty->id));
        $term = $this->term('Correct');
        $campaign = $this->campaign('revision', [$term]);
        $this->refuses(fn () => $this->service()->archive($this->manager, $campaign));
        $this->service()->start($this->manager, $campaign);
        $this->service()->confirm($this->participant, $campaign->fresh(), $term);
        $this->service()->archive($this->manager, $campaign->fresh());
        self::assertNotNull($campaign->fresh()->archived_at);
        $this->refuses(fn () => $this->service()->delete($this->manager, $campaign->fresh()));
        $this->service()->unarchive($this->manager, $campaign->fresh());
        self::assertNull($campaign->fresh()->archived_at);
        self::assertSame('completed', $campaign->fresh()->status);
        self::assertGreaterThan(0, $campaign->events()->count());
    }

    public function test_metrics_count_each_active_term_once_and_never_round_incomplete_work_to_100(): void
    {
        $campaign = $this->campaign();
        $template = $this->term('Correct')->getAttributes();
        unset($template['id']);
        $terms = [];
        for ($index = 2; $index <= 2001; $index++) {
            $terms[] = array_replace($template, ['id' => $index, 'label' => 'metric.'.$index, 'key' => json_encode(['metric.'.$index])]);
        }
        foreach (array_chunk($terms, 100) as $chunk) {
            Term::insert($chunk);
        }
        $rows = [];
        for ($index = 1; $index <= 2001; $index++) {
            $rows[] = ['translation_campaign_id' => $campaign->id, 'term_id' => $index, 'active' => true, 'direct' => true,
                'attribute' => 'label', 'status' => $index === 2001 ? 'to_process' : 'confirmed', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            CampaignParticipation::insert($chunk);
        }
        $metrics = $campaign->fresh()->metrics();
        self::assertSame(2001, $metrics['total']);
        self::assertSame(2000, $metrics['confirmed']);
        self::assertSame(99.9, $metrics['work_progress']);
        self::assertSame(99.9, $metrics['confirmed_progress']);
        CampaignParticipation::where('translation_campaign_id', $campaign->id)->where('term_id', 2001)->update(['active' => false]);
        self::assertSame(100.0, $campaign->fresh()->metrics()['confirmed_progress']);
    }

    public function test_migration_and_rollback_preserve_existing_catalogue_projection_triggers(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite table rebuild regression.');
        }
        $migration = require database_path('migrations/2026_10_13_090000_create_translation_campaigns.php');
        $term = $this->term('Current');
        $triggerNames = DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'terms')->orderBy('name')->pluck('name')->all();
        self::assertNotEmpty($triggerNames);
        self::assertTrue(TermState::matchingState('validated', 'fr')->whereKey($term->id)->exists());
        foreach (['down', 'up'] as $direction) {
            $migration->$direction();
            self::assertSame($triggerNames, DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'terms')->orderBy('name')->pluck('name')->all());
            DB::table('terms')->where('id', $term->id)->update(['validated' => json_encode(['label' => ['fr' => 'Updated '.$direction]]), 'search_text' => 'Updated '.$direction]);
            self::assertTrue(TermState::matchingState('validated', 'fr')->whereKey($term->id)->exists());
            self::assertStringContainsString('Updated '.$direction, DB::table('term_search')->where('term_id', $term->id)->value('search_text'));
        }
    }
}
