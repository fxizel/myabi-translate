<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $locale = 'fr'): User
    {
        return User::factory()->create([
            'password' => 'Locale-password-123', 'roles' => ['reader' => ['de', 'fr', 'it']],
            'locale' => $locale, 'active' => true, 'is_technical' => false,
        ]);
    }

    public function test_profile_language_change_immediately_translates_its_confirmation(): void
    {
        $user = $this->user('de');

        $this->actingAs($user)->from('/profile')->patch('/profile', ['locale' => 'fr'])
            ->assertRedirect('/profile')->assertSessionHasNoErrors()
            ->assertSessionHas('locale', 'fr')->assertSessionHas('status', 'Profil enregistré.');

        $this->assertSame('fr', $user->fresh()->locale);
        $this->get('/profile')->assertOk()->assertSee('lang="fr"', false)->assertSee('Profil enregistré.');
    }

    public function test_guest_and_authenticated_language_selectors_keep_french(): void
    {
        $this->from('/login')->post('/locale', ['locale' => 'fr'])
            ->assertRedirect('/login')->assertSessionHas('locale', 'fr');
        $this->get('/login')->assertOk()->assertSee('lang="fr"', false);

        $user = $this->user('de');
        $this->actingAs($user)->from('/profile')->post('/locale', ['locale' => 'fr'])
            ->assertRedirect('/profile')->assertSessionHas('locale', 'fr');
        $this->assertSame('fr', $user->fresh()->locale);
        $this->get('/profile')->assertOk()->assertSee('lang="fr"', false);
    }

    public function test_logout_retains_french_after_invalidating_the_authenticated_session(): void
    {
        $user = $this->user();
        $this->actingAs($user)->withSession(['locale' => 'de', 'security.version' => $user->session_version]);
        $sessionId = session()->getId();

        $this->post('/logout')->assertRedirect()->assertSessionHas('locale', 'fr')
            ->assertSessionMissing('security')->assertSessionMissing('auth.password_confirmed_at');
        $this->assertGuest();
        $this->assertNotSame($sessionId, session()->getId());
        $this->get('/login')->assertOk()->assertSee('lang="fr"', false);
    }

    public function test_login_uses_the_account_language_instead_of_the_previous_guest_preference(): void
    {
        $user = $this->user();

        $this->withSession(['locale' => 'de'])->post('/login', [
            'email' => $user->email, 'password' => 'Locale-password-123',
        ])->assertRedirect('/')->assertSessionHas('locale', 'fr');

        $this->assertAuthenticatedAs($user);
        $this->get('/profile')->assertOk()->assertSee('lang="fr"', false);
    }

    public function test_expired_session_retains_french_and_the_localized_expiry_error(): void
    {
        $user = $this->user();
        $this->actingAs($user)->withSession([
            'locale' => 'de', 'security.started_at' => now()->subHours(13)->timestamp,
            'security.last_seen_at' => now()->timestamp, 'security.version' => $user->session_version,
        ])->get('/profile')->assertRedirect(route('login'))->assertSessionHas('locale', 'fr')
            ->assertSessionHasErrors(['email' => 'Votre session a expiré. Veuillez vous reconnecter.'])
            ->assertSessionMissing('security');

        $this->assertGuest();
        $this->get('/login')->assertOk()->assertSee('lang="fr"', false);
    }

    #[DataProvider('passwordConfirmationTranslations')]
    public function test_failed_password_confirmation_is_localized(string $locale, string $message): void
    {
        $this->actingAs($this->user($locale))->from('/user/confirm-password')
            ->post('/user/confirm-password', ['password' => 'Incorrect-password'])
            ->assertRedirect('/user/confirm-password')->assertSessionHasErrors(['password' => $message]);
    }

    public static function passwordConfirmationTranslations(): array
    {
        return [
            'French' => ['fr', 'Le mot de passe saisi est incorrect.'],
            'German' => ['de', 'Das eingegebene Passwort ist falsch.'],
            'Italian' => ['it', 'La password inserita non è corretta.'],
        ];
    }
}
