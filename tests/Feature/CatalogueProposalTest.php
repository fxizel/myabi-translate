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
        }
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
        $response->assertDontSee('id="proposal-'.$term->id.'"', false)->assertDontSee('Italian primary');
        $details = $this->get('/terms/'.$term->id)->assertOk()->viewData('proposals');
        self::assertGreaterThan($details->search(fn ($proposal) => $proposal->id === $latest->id), $details->search(fn ($proposal) => $proposal->value === 'Earlier tie'));
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

    private function term(string $type): Term
    {
        return Term::create(['type' => $type, 'organization_id' => null, 'identity_hash' => hash('sha256', $type),
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
