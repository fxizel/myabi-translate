<?php

namespace Tests\Feature;

use App\Domain\Devconf\FormatRegistry;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Revision;
use App\Models\Term;
use App\Models\User;
use App\Services\SourceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogueProposalTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireTwoFactor::class);
        $organization = Organisation::create(['code' => 'QA', 'name' => 'Synthetic catalogue', 'active' => true]);
        $this->actor = User::factory()->create(['organization_id' => $organization->id, 'roles' => ['manager'], 'locale' => 'fr']);
        $source = $this->mock(SourceRecord::class);
        $source->shouldReceive('attributes')->andReturnUsing(function (Term $term) {
            return collect(FormatRegistry::get($term->type)->attributeNames)->mapWithKeys(fn ($attribute) => [
                $attribute => ['reference' => 'Reference '.$attribute, 'translations' => [], 'columns' => ['fr' => 'fr_CH', 'it' => 'it_CH']],
            ])->all();
        });
        $source->shouldReceive('row')->andReturn([]);
        $this->actingAs($this->actor);
    }

    public function test_secondary_attributes_keep_the_primary_reference_value_and_editor_available(): void
    {
        foreach (['law', 'incident'] as $type) {
            $term = $this->term($type);
            $attributes = FormatRegistry::get($type)->attributeNames;
            foreach (array_slice($attributes, 1) as $attribute) {
                $this->proposal($term, $attribute, 'Pending '.$attribute);
            }
            $response = $this->get('/terms?type='.$type.'&language=fr')->assertOk();
            $row = $response->viewData('terms')->first();
            self::assertSame('Primary validated', $row->current_value);
            self::assertNull($row->pending_value);
            self::assertSame(0, $row->pending_count);
            self::assertCount(count($attributes) - 1, $row->other_pending_attributes);
            $page = $this->xpath($response->getContent());
            self::assertSame('Reference '.$attributes[0], $page->evaluate('string(//a[@class="source-text"])'));
            self::assertSame($attributes[0], $page->evaluate('string(//form[textarea]/input[@name="attribute"]/@value)'));
            self::assertSame(1, $page->query('//textarea[@id="proposal-'.$term->id.'"]')->length);
            self::assertSame(1, $page->query('//input[@data-select-item]')->length);
            foreach (array_slice($attributes, 1, preserve_keys: true) as $index => $attribute) {
                $response->assertDontSee('Pending '.$attribute)->assertSee('?language=fr#translation-'.$index, false);
            }
            $details = $this->xpath($this->get('/terms/'.$term->id)->assertOk()->getContent());
            self::assertSame(count($attributes) - 1, $details->query('//form/input[@name="proposal_id"]')->length);
            $secondaryPanel = '//details[@id="incident-secondary-attributes"]';
            if ($type === 'incident') {
                self::assertSame('Reference TEXT', $details->evaluate('normalize-space(//main//h1)'));
                self::assertSame(1, $details->query('//*[@id="proposals"]/following-sibling::details[@id="incident-secondary-attributes" and not(@open)]')->length);
                self::assertSame(0, $details->query($secondaryPanel.'//*[@id="translation-0"]')->length);
                foreach (array_slice($attributes, 1, preserve_keys: true) as $index => $attribute) {
                    self::assertSame($attribute, $details->evaluate('string('.$secondaryPanel.'//*[@id="translation-'.$index.'"]//form[.//textarea]/input[@name="attribute"]/@value)'));
                }
            } else {
                self::assertSame(0, $details->query($secondaryPanel)->length);
            }
        }
    }

    public function test_failed_incident_secondary_proposal_reopens_its_panel_with_the_draft(): void
    {
        $term = $this->term('incident');
        $url = '/terms/'.$term->id.'?language=fr';
        $this->from($url)->post('/terms/'.$term->id.'/proposals', [
            'term_id' => $term->id, 'attribute' => 'SHORTTEXT', 'language' => 'fr',
            'value' => 'Unsaved short text', 'lock_version' => 0,
        ])->assertRedirect($url)->assertSessionHasErrors('version');

        $details = $this->xpath($this->get($url)->assertOk()->getContent());
        self::assertSame('Unsaved short text', $details->evaluate('string(//details[@id="incident-secondary-attributes" and @open]//textarea[@id="new-proposal-1" and @data-restored="1"])'));
    }

    public function test_concurrent_proposals_are_counted_per_attribute_and_language_in_stable_newest_order(): void
    {
        $term = $this->term('law');
        $this->proposal($term, 'Cause Text', 'Earlier tie');
        $latest = $this->proposal($term, 'Cause Text', 'Newest tie');
        $this->proposal($term, 'Cause Text', 'Earlier date')->update(['created_at' => '2026-09-01 09:00:00']);
        $this->proposal($term, 'Cause Text', 'Italian primary', 'it');
        $this->proposal($term, 'Short Justification', 'Secondary');
        $this->proposal($term, 'Cause Text', 'Rejected')->update(['status' => 'rejected']);
        $response = $this->get('/terms?language=fr')->assertOk()->assertSee('3 propositions en attente')->assertSee('La plus récente est affichée.');
        $row = $response->viewData('terms')->first();
        self::assertSame($latest->value, $row->pending_value);
        self::assertSame(3, $row->pending_count);
        self::assertSame('validated', $row->current_state);
        $response->assertDontSee('id="proposal-'.$term->id.'"', false)->assertDontSee('Italian primary');
        $details = $this->get('/terms/'.$term->id)->assertOk()->viewData('proposals');
        self::assertGreaterThan($details->search(fn ($proposal) => $proposal->id === $latest->id), $details->search(fn ($proposal) => $proposal->value === 'Earlier tie'));
    }

    public function test_catalogue_badge_requires_a_pending_proposal_for_the_displayed_cell(): void
    {
        $term = $this->term('law');
        $term->update(['validated' => []]);
        $this->proposal($term, 'Short Justification', 'French secondary');
        $this->proposal($term, 'Cause Text', 'Italian primary', 'it');
        $this->proposal($term, 'Cause Text', 'Rejected primary')->update(['status' => 'rejected']);
        $stateBadge = '//td[@data-column="state"]/span';

        $french = $this->get('/terms?language=fr')->assertOk();
        self::assertSame('À traduire', $this->xpath($french->getContent())->evaluate('string('.$stateBadge.')'));

        $this->proposal($term, 'Cause Text', 'French primary');
        $french = $this->get('/terms?language=fr&state=missing')->assertOk();
        self::assertSame('Proposée', $this->xpath($french->getContent())->evaluate('string('.$stateBadge.')'));
        $french->assertSee('Aucune traduction validée');
    }

    public function test_detail_shows_pending_only_for_the_matching_unvalidated_translation(): void
    {
        $term = $this->term('law');
        $pending = $this->proposal($term, 'Short Justification', 'French candidate');
        $this->proposal($term, 'Legal Remedies', 'Rejected candidate')->update(['status' => 'rejected']);
        $this->proposal($term, 'Legal Remedies', 'Italian candidate', 'it');
        for ($i = 0; $i < 50; $i++) {
            $this->proposal($term, 'Cause Text', 'Replacement '.$i);
        }

        $response = $this->get('/terms/'.$term->id.'?language=fr')->assertOk()
            ->assertDontSee('id="proposal-'.$pending->id.'"', false);
        $page = $this->xpath($response->getContent());
        self::assertSame('Validée', $page->evaluate('string(//*[@id="translation-0"]//span[@class="badge badge-validated"])'));
        self::assertSame('Proposée', $page->evaluate('string(//*[@id="translation-1"]//span[@class="badge badge-pending"])'));
        self::assertSame('Aucune traduction validée', $page->evaluate('string(//*[@id="translation-1"]/p[@class="translation-text"])'));
        self::assertSame('À traduire', $page->evaluate('string(//*[@id="translation-2"]//span[@class="badge badge-missing"])'));
    }

    public function test_failed_corrections_restore_only_their_form_and_success_clears_the_draft(): void
    {
        $term = $this->term('law');
        $proposal = $this->proposal($term, 'Short Justification', 'Recorded proposal');
        $other = $this->proposal($term, 'Short Justification', 'Another proposal');
        $url = '/terms/'.$term->id.'?language=fr';
        $payload = ['proposal_id' => $proposal->id, 'term_id' => $term->id, 'attribute' => $proposal->attribute,
            'language' => 'fr', 'value' => 'Unsaved correction', 'lock_version' => 0];
        $this->from($url)->patch('/proposals/'.$proposal->id, $payload)->assertRedirect($url)->assertSessionHasErrors('version');
        $page = $this->xpath($this->get($url)->assertOk()->getContent());
        $editor = $page->query('//textarea[@id="correct-proposal-'.$proposal->id.'"]')->item(0);
        self::assertSame('Unsaved correction', $editor->textContent);
        self::assertSame('1', $editor->getAttribute('data-restored'));
        self::assertSame(1, $page->query('//textarea[@data-restored="1"]')->length);
        self::assertSame(1, $page->query('//details[@open]//textarea[@id="correct-proposal-'.$proposal->id.'"]')->length);
        self::assertSame('0', $page->evaluate('string(//textarea[@id="correct-proposal-'.$other->id.'"]/@data-restored)'));
        self::assertSame('Short Justification', $page->evaluate('string(//form[input[@name="proposal_id"][@value="'.$proposal->id.'"]]/input[@name="attribute"]/@value)'));

        $payload['lock_version'] = $proposal->lock_version;
        $this->from($url)->patch('/proposals/'.$proposal->id, $payload)->assertRedirect($url)->assertSessionHasNoErrors();
        $page = $this->xpath($this->get($url)->assertOk()->getContent());
        self::assertSame(0, $page->query('//textarea[@data-restored="1"]')->length);
        self::assertSame('Unsaved correction', $proposal->fresh()->value);
    }

    public function test_encoding_error_and_empty_corrections_remain_restored_drafts(): void
    {
        $term = $this->term('law');
        $proposal = $this->proposal($term, 'Legal Remedies', 'Recorded proposal');
        foreach (['Unsupported 😀', ''] as $value) {
            $url = '/terms/'.$term->id;
            $this->from($url)->patch('/proposals/'.$proposal->id, ['proposal_id' => $proposal->id, 'value' => $value, 'lock_version' => $proposal->lock_version])
                ->assertRedirect($url)->assertSessionHasErrors('value');
            $page = $this->xpath($this->get($url)->assertOk()->getContent());
            $editor = $page->query('//textarea[@id="correct-proposal-'.$proposal->id.'"]')->item(0);
            self::assertSame($value, $editor->textContent);
            self::assertSame('1', $editor->getAttribute('data-restored'));
            self::assertSame('Recorded proposal', $proposal->fresh()->value);
        }
    }

    public function test_rejected_proposal_restore_does_not_duplicate_the_draft_in_the_creation_form(): void
    {
        $term = $this->term('law');
        $proposal = $this->proposal($term, 'Legal Remedies', 'Rejected value');
        $proposal->update(['status' => 'rejected']);
        $response = $this->withSession(['_old_input' => ['term_id' => $term->id, 'attribute' => $proposal->attribute,
            'language' => 'fr', 'supersedes_id' => $proposal->id, 'value' => 'Rejected correction']])->get('/terms/'.$term->id)->assertOk();
        $page = $this->xpath($response->getContent());
        self::assertSame(1, $page->query('//textarea[@data-restored="1"]')->length);
        self::assertSame('Rejected correction', $page->evaluate('string(//textarea[@id="replace-proposal-'.$proposal->id.'"])'));
        self::assertSame('', $page->evaluate('string(//textarea[@id="new-proposal-2"])'));
    }

    public function test_revision_restore_marks_only_values_supplied_by_the_revision(): void
    {
        $term = $this->term('law');
        Revision::create(['term_id' => $term->id, 'number' => 1, 'version_id' => 1, 'source_import_id' => 1,
            'source_offset' => 0, 'source_length' => 1, 'validated' => ['Cause Text' => ['fr' => 'Revision value']],
            'metadata' => [], 'origin' => 'import', 'origin_id' => 1, 'created_by' => $this->actor->id, 'created_at' => now()]);
        $page = $this->xpath($this->get('/terms/'.$term->id.'?restore_revision=1')->assertOk()->getContent());
        self::assertSame(1, $page->query('//textarea[@data-restored="1"]')->length);
        self::assertSame('Revision value', $page->evaluate('string(//textarea[@id="new-proposal-0"])'));
        self::assertSame(3, $page->query('//input[@name="restored_revision_id"]')->length);
    }

    public function test_translator_can_arbitrate_without_validation_or_other_language_rights(): void
    {
        $term = $this->term('law')->fresh();
        $foreign = $this->proposal($term, 'Cause Text', 'Italian candidate', 'it');
        $proposal = $this->proposal($term, 'Cause Text', 'French candidate');
        $translator = User::factory()->create(['roles' => ['translator' => ['fr']], 'locale' => 'fr']);
        $url = '/proposals/'.$proposal->id.'/decide';
        $generation = DB::table('catalogue_state')->where('id', 1)->value('generation');
        $oldContext = $proposal->context_fingerprint;

        $this->actingAs($translator)->get('/validation')->assertForbidden();
        $this->get('/terms?language=fr')->assertOk()->assertSee('data-arbitration-id="'.$proposal->id.'"', false);
        $this->get('/terms/'.$term->id.'?language=fr')->assertOk()->assertSee('value="arbitrate"', false)->assertDontSee('value="reject"', false);
        $this->postJson('/proposals/'.$foreign->id.'/decide', ['decision' => 'arbitrate', 'lock_version' => 1])->assertForbidden();
        $this->postJson($url, ['decision' => 'arbitrate', 'lock_version' => 0])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->post($url, ['decision' => 'arbitrate', 'lock_version' => 1])->assertRedirect()->assertSessionHasNoErrors();

        $proposal->refresh();
        self::assertSame(Proposal::ARBITRATION, $proposal->status);
        self::assertSame(2, $proposal->lock_version);
        self::assertSame($this->actor->id, $proposal->author_id);
        self::assertSame($this->actor->organization_id, $proposal->organization_id);
        self::assertSame($oldContext, $proposal->context_fingerprint);
        self::assertNull($proposal->decided_at);
        self::assertSame('Primary validated', $term->fresh()->validated['Cause Text']['fr']);
        self::assertSame($term->revision_no, $term->fresh()->revision_no);
        self::assertSame((int) $generation + 1, (int) DB::table('catalogue_state')->where('id', 1)->value('generation'));

        $this->postJson($url, ['decision' => 'arbitrate', 'lock_version' => 2])->assertUnprocessable();
        $this->postJson($url, ['decision' => 'validate', 'lock_version' => 2])->assertForbidden();
        $this->patchJson('/proposals/'.$proposal->id, ['value' => 'Unauthorised edit', 'lock_version' => 2])->assertForbidden();
        $this->actingAs($this->actor)->postJson($url, ['decision' => 'validate', 'lock_version' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('override');
    }

    public function test_direct_and_selected_arbitration_keep_creation_and_mixed_batch_atomic(): void
    {
        $translator = User::factory()->create(['roles' => ['translator' => ['fr']], 'locale' => 'fr']);
        $term = $this->term('server')->fresh();
        $otherTerm = $this->term('law');
        $existing = $this->proposal($otherTerm, 'Cause Text', 'Existing candidate');
        $draft = ['attribute' => 'label', 'language' => 'fr', 'value' => 'Draft candidate', 'lock_version' => $term->lock_version];
        $directUrl = '/terms/'.$term->id.'/proposals';
        $this->actingAs($translator)->postJson($directUrl, ['intent' => 'arbitrate', ...$draft, 'attribute' => 'Wrong attribute'])
            ->assertUnprocessable()->assertJsonValidationErrors('value');
        self::assertFalse($term->proposals()->exists());

        $response = $this->postJson($directUrl, ['intent' => 'arbitrate', ...$draft])->assertCreated()->assertJsonPath('version', 2);
        $direct = Proposal::findOrFail($response->json('id'));
        self::assertSame(Proposal::ARBITRATION, $direct->status);
        self::assertSame($translator->id, $direct->author_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'proposal.created', 'entity_id' => $direct->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'proposal.arbitrate', 'entity_id' => $direct->id]);

        $payload = ['intent' => 'arbitrate', 'confirmed' => 1, 'selection' => [$existing->id => 1],
            'rows' => [$term->id => ['selected' => 1, ...$draft, 'value' => 'Distinct candidate', 'lock_version' => 0]]];
        $this->postJson('/proposals/bulk-store', $payload)->assertUnprocessable()->assertJsonValidationErrors('version');
        self::assertSame('pending', $existing->fresh()->status);
        self::assertSame(1, $term->proposals()->count());

        $payload['rows'][$term->id]['lock_version'] = $term->lock_version;
        $this->post('/proposals/bulk-store', $payload)->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame(Proposal::ARBITRATION, $existing->fresh()->status);
        self::assertSame(2, $term->proposals()->where('status', Proposal::ARBITRATION)->count());
    }

    public function test_explicit_decisions_protect_arbitration_competitors_until_motivated_rejection(): void
    {
        $term = $this->term('law');
        $winner = $this->proposal($term, 'Cause Text', 'Preferred candidate');
        $protected = $this->proposal($term, 'Cause Text', 'Candidate in arbitration');
        $ordinary = $this->proposal($term, 'Cause Text', 'Ordinary competitor');
        $otherCell = $this->proposal($term, 'Short Justification', 'Another attribute');
        $validator = User::factory()->create(['roles' => ['validator' => ['fr']], 'locale' => 'fr']);
        app(SourceRecord::class)->shouldReceive('snapshot')->once()->andReturn(new Revision);
        $this->actingAs($validator)->post('/proposals/bulk', ['decision' => 'arbitrate', 'selection' => [$protected->id => 1, $otherCell->id => 1], 'confirmed' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->postJson('/proposals/'.$winner->id.'/decide', ['decision' => 'validate', 'lock_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->postJson('/proposals/bulk', ['decision' => 'validate', 'selection' => [$winner->id => 1, $protected->id => 2], 'confirmed' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->postJson('/proposals/bulk', ['decision' => 'reject', 'selection' => [$protected->id => 2], 'confirmed' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        self::assertSame(Proposal::ARBITRATION, $protected->fresh()->status);
        self::assertSame('pending', $ordinary->fresh()->status);

        $this->post('/proposals/bulk', ['decision' => 'reject', 'selection' => [$protected->id => 2], 'reason' => 'Motif synthétique', 'confirmed' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/proposals/'.$winner->id.'/decide', ['decision' => 'arbitrate', 'lock_version' => 1])->assertRedirect();
        $this->post('/proposals/bulk', ['decision' => 'validate', 'selection' => [$winner->id => 2], 'confirmed' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('Motif synthétique', $protected->fresh()->rejection_reason);
        self::assertSame('superseded', $ordinary->fresh()->rejection_reason);
        self::assertSame(Proposal::ARBITRATION, $otherCell->fresh()->status);
        self::assertSame('validated', $winner->fresh()->status);
        self::assertSame('Preferred candidate', $term->fresh()->validated['Cause Text']['fr']);
    }

    public function test_arbitration_filter_matches_main_and_visible_secondary_badges_in_exact_cells(): void
    {
        $main = $this->term('law');
        $main->update(['validated' => ['Cause Text' => ['fr' => '0']], 'review_needed' => ['Cause Text' => ['fr' => true]]]);
        $arbitration = $this->proposal($main, 'Cause Text', 'Prioritised candidate');
        $arbitration->update(['status' => Proposal::ARBITRATION]);
        $this->proposal($main, 'Cause Text', 'Newer ordinary candidate')->update(['created_at' => now()]);
        $secondary = $this->term('incident');
        $secondary->update(['validated' => []]);
        $this->proposal($secondary, 'TEXT', 'Primary pending');
        $this->proposal($secondary, 'SHORTTEXT', 'Secondary in arbitration')->update(['status' => Proposal::ARBITRATION]);
        $other = $this->term('server');
        $this->proposal($other, 'Label', 'Different attribute')->update(['status' => Proposal::ARBITRATION]);
        $this->proposal($other, 'label', 'Different language', 'FR')->update(['status' => Proposal::ARBITRATION]);
        $obsolete = $this->term('monomot');
        $obsolete->update(['obsolete' => true]);
        $this->proposal($obsolete, 'label', 'Obsolete candidate')
            ->update(['status' => Proposal::ARBITRATION]);

        $response = $this->get('/terms?state=arbitration&language=fr')->assertOk();
        $rows = $response->viewData('terms')->keyBy('id');
        self::assertEqualsCanonicalizing([$main->id, $secondary->id], $rows->keys()->all());
        self::assertSame(Proposal::ARBITRATION, $rows[$main->id]->current_state);
        self::assertSame($arbitration->id, $rows[$main->id]->pending_id);
        self::assertSame('0', $rows[$main->id]->current_value);
        self::assertSame('pending', $rows[$secondary->id]->current_state);
        $response->assertSee('match_proposal='.$arbitration->id.'#proposal-'.$arbitration->id, false)
            ->assertSee('?language=fr#translation-1', false);
        self::assertSame([$secondary->id], $this->get('/terms?state=pending&language=fr')->assertOk()->viewData('terms')->pluck('id')->all());
        $this->get('/terms/'.$main->id.'?language=fr')->assertOk()->assertSee('badge-review', false)
            ->assertSee('badge-arbitration', false)->assertSee('name="lock_version"', false);
    }

    private function term(string $type): Term
    {
        return Term::create(['type' => $type, 'identity_hash' => hash('sha256', $type),
            'key' => [$type], 'label' => 'Synthetic '.$type, 'context' => '', 'source_text' => 'Aggregated references',
            'search_text' => 'Synthetic catalogue', 'semantic_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64),
            'source_import_id' => 1, 'source_offset' => 0, 'source_length' => 1,
            'validated' => [FormatRegistry::get($type)->attributeNames[0] => ['fr' => 'Primary validated']],
            'review_needed' => [], 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
    }

    private function proposal(Term $term, string $attribute, string $value, string $language = 'fr'): Proposal
    {
        return Proposal::create(['term_id' => $term->id, 'attribute' => $attribute, 'language' => $language, 'value' => $value,
            'value_hash' => hash('sha256', $value), 'status' => 'pending', 'author_id' => $this->actor->id,
            'organization_id' => $this->actor->organization_id, 'anomalies' => [], 'edit_history' => [], 'created_at' => '2026-09-02 09:00:00'])->fresh();
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }
}
