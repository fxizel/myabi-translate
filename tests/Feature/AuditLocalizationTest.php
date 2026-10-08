<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTwoFactor;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

class AuditLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_recorded_audit_events_have_labels_in_each_interface_language(): void
    {
        // These two event suffixes are assembled from authorized workflow decisions.
        $actions = ['proposal.validate', 'proposal.reject'];
        foreach (File::allFiles(app_path()) as $file) {
            preg_match_all('/->record\(([^,\n]+)/', $file->getContents(), $calls);
            foreach ($calls[1] as $argument) {
                preg_match_all("/'([a-z_]+\\.[a-z_]+)'/", $argument, $matches);
                array_push($actions, ...$matches[1]);
            }
        }
        $actions = array_unique($actions);
        $this->assertGreaterThan(50, count($actions));
        $keys = array_keys(Arr::dot(require lang_path('de/audit.php')));
        foreach (['de', 'fr', 'it'] as $locale) {
            $translations = Arr::dot(require lang_path($locale.'/audit.php'));
            $this->assertSame($keys, array_keys($translations));
            foreach ($translations as $key => $label) {
                $this->assertIsString($label);
                $this->assertNotSame('', $label, $locale.': '.$key);
            }
            foreach ($actions as $action) {
                $this->assertTrue(Lang::hasForLocale('audit.events.'.$action, $locale), $locale.': '.$action);
            }
        }
    }

    public function test_audit_and_profile_labels_follow_the_locale_without_changing_filter_values_or_export(): void
    {
        $this->withoutMiddleware(RequireTwoFactor::class);
        $user = User::factory()->create(['roles' => ['manager' => ['fr']], 'locale' => 'fr']);
        $this->actingAs($user);
        $event = app(AuditService::class)->record('profile.updated', $user, ['locale' => 'de'], ['locale' => 'fr'], $user);
        app(AuditService::class)->record('account.created', $user, [], [], $user);

        foreach (['fr' => 'Profil modifié', 'de' => 'Profil geändert', 'it' => 'Profilo modificato'] as $locale => $label) {
            $user->update(['locale' => $locale]);
            $response = $this->get('/audit?action=profile.updated')->assertOk()->assertSee($label)
                ->assertSee('value="profile.updated"', false);
            $document = new \DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($document);
            $this->assertSame('profile.updated', $xpath->evaluate('string(//select[@name="action"]/option[@selected]/@value)'));
            $this->assertSame($label, trim($xpath->evaluate('string(//select[@name="action"]/option[@selected])')));
            $this->assertSame(1, $xpath->query('//table/tbody/tr')->length);
            $this->assertSame($label, trim($xpath->evaluate('string(//table/tbody/tr/td[3])')));
            $this->assertStringStartsWith(__('audit.entities.App\\Models\\User'), trim($xpath->evaluate('string(//table/tbody/tr/td[4])')));

            $this->get('/profile')->assertOk()->assertSee($label)->assertDontSee('profile.updated');
            $csv = $this->get('/audit/export?action=profile.updated')->assertOk()->streamedContent();
            $this->assertStringContainsString('profile.updated', $csv);
            $this->assertStringContainsString('App\\Models\\User', $csv);
            $this->assertStringNotContainsString($label, $csv);
        }

        $event->refresh();
        $this->assertSame('profile.updated', $event->action);
        $this->assertSame(User::class, $event->entity_type);
        $this->assertSame(['locale' => 'de'], $event->before_values);
        $this->assertSame(['locale' => 'fr'], $event->after_values);
    }

    public function test_origins_are_translated_and_unknown_codes_remain_readable_and_escaped(): void
    {
        app()->setLocale('fr');
        $this->assertSame('Proposition', trim(view('partials.event-label', ['value' => 'proposal', 'group' => 'origins'])->render()));
        $this->assertSame('Modification du périmètre', trim(view('partials.event-label', ['value' => 'scope', 'group' => 'origins'])->render()));
        $this->assertSame('future.event', trim(view('partials.event-label', ['value' => 'future.event'])->render()));
        $this->assertSame('&lt;script&gt;unknown&lt;/script&gt;', trim(view('partials.event-label', ['value' => '<script>unknown</script>'])->render()));
        $this->assertSame('future_entity', trim(view('partials.event-label', ['value' => 'future_entity', 'group' => 'entities'])->render()));
    }
}
