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
use App\Services\StorageBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireTwoFactor::class);
        $organization = Organisation::create(['code' => 'VD', 'name' => 'Vaud', 'active' => true]);
        $this->manager = User::factory()->create([
            'organization_id' => $organization->id,
            'roles' => ['manager' => ['de', 'fr', 'it', 'en']],
            'locale' => 'fr',
        ]);
        $this->actingAs($this->manager);
        $this->mock(StorageBudget::class)->shouldReceive('usage')->andReturn([
            'bytes' => 0, 'quota' => 1024 * 1024, 'ratio' => 0, 'warning' => false,
        ]);
    }

    public function test_type_choices_follow_the_interface_locale_and_keep_their_original_values(): void
    {
        foreach (['fr' => 'Formulaire', 'de' => 'Formular', 'it' => 'Formulario'] as $locale => $formLabel) {
            $this->manager->update(['locale' => $locale]);
            foreach (['terms', 'imports', 'publications'] as $path) {
                $response = $this->get('/'.$path)->assertOk()->assertSee($formLabel);
                $page = $this->pageXPath($response->getContent());
                $choices = $path === 'publications'
                    ? $page->query('//input[@name="types[]"]')
                    : $page->query('//select[@name="type"]/option[@value!=""]');
                $this->assertSame(array_keys(FormatRegistry::all()), array_map(
                    fn ($choice) => $choice->getAttribute('value'), iterator_to_array($choices)
                ));
            }
        }
    }

    public function test_translated_attribute_labels_preserve_form_values_sources_and_technical_trace(): void
    {
        $attributes = [];
        foreach (['Cause Text', 'Short Justification', 'Legal Remedies', 'Unknown <attribute>'] as $name) {
            $attributes[$name] = ['reference' => 'Unveränderte Referenz', 'translations' => ['fr' => 'Valeur importée'], 'columns' => ['fr' => $name.' fr_CH']];
        }
        $this->mock(SourceRecord::class, function ($mock) use ($attributes) {
            $mock->shouldReceive('attributes')->andReturn($attributes);
            $mock->shouldReceive('row')->andReturn(['Cause Text' => 'Valeur technique originale']);
        });
        $term = Term::create([
            'type' => 'law', 'organization_id' => null, 'identity_hash' => hash('sha256', 'synthetic-law'), 'key' => ['synthetic-law'],
            'label' => 'synthetic-law', 'context' => 'test', 'source_text' => 'Unveränderte Referenz', 'search_text' => 'synthetic-law',
            'semantic_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64), 'source_import_id' => 1,
            'source_offset' => 0, 'source_length' => 1, 'validated' => ['Cause Text' => ['fr' => 'Valeur validée']],
            'review_needed' => ['Cause Text' => ['fr' => true]], 'created_by' => $this->manager->id, 'updated_by' => $this->manager->id,
        ]);
        foreach (['Cause Text' => 'pending', 'Legal Remedies' => 'rejected'] as $attribute => $status) {
            Proposal::create([
                'term_id' => $term->id, 'attribute' => $attribute, 'language' => 'fr', 'value' => 'Proposition inchangée',
                'value_hash' => hash('sha256', 'Proposition inchangée'), 'status' => $status, 'author_id' => $this->manager->id,
                'organization_id' => $this->manager->organization_id, 'anomalies' => [], 'edit_history' => [],
            ]);
        }
        Revision::create([
            'term_id' => $term->id, 'number' => 1, 'version_id' => 1, 'source_import_id' => 1, 'source_offset' => 0, 'source_length' => 1,
            'validated' => $term->validated, 'metadata' => [], 'origin' => 'import', 'origin_id' => 1, 'created_by' => $this->manager->id, 'created_at' => now(),
        ]);

        $response = $this->get('/terms/'.$term->id.'?from=1&to=1')->assertOk()
            ->assertSee('Catalogue des lois')->assertSee('Texte du motif')->assertSee('Justification courte')->assertSee('Voies de recours')
            ->assertSee('Unveränderte Referenz')->assertSee('Proposition inchangée')->assertSee('Unknown &lt;attribute&gt;', false)
            ->assertDontSee('catalogue.attributes.');
        $page = $this->pageXPath($response->getContent());
        $submittedAttributes = array_map(fn ($input) => $input->getAttribute('value'), iterator_to_array($page->query('//input[@name="attribute"]')));
        $this->assertSame(['Cause Text', 'Cause Text', 'Short Justification', 'Legal Remedies', 'Unknown <attribute>', 'Legal Remedies', 'Cause Text'], $submittedAttributes);
        $this->assertSame(1, $page->query('//th[text()="Cause Text"]/following-sibling::td[text()="Valeur technique originale"]')->length);
        $this->get('/validation')->assertOk()->assertSee('Catalogue des lois · Texte du motif')->assertSee('Proposition inchangée');
    }

    public function test_every_known_type_and_attribute_has_an_explicit_translation_in_each_interface_language(): void
    {
        foreach (['de', 'fr', 'it'] as $locale) {
            foreach (FormatRegistry::all() as $format) {
                $this->assertTrue(app('translator')->hasForLocale('catalogue.types.'.$format->code, $locale));
                foreach ($format->attributeNames as $attribute) {
                    $this->assertTrue(app('translator')->hasForLocale('catalogue.attributes.'.$attribute, $locale));
                }
            }
        }
    }

    private function pageXPath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }
}
