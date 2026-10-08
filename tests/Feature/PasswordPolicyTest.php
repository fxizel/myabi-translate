<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_minimum_defaults_to_eight_and_reads_the_environment_override(): void
    {
        $environment = Env::getRepository();
        $previous = $environment->get('AUTH_PASSWORD_MIN_LENGTH');
        $environment->clear('AUTH_PASSWORD_MIN_LENGTH');
        try {
            $configuration = require config_path('auth.php');
            $this->assertSame(8, $configuration['password_min_length']);

            $environment->set('AUTH_PASSWORD_MIN_LENGTH', '14');
            $configuration = require config_path('auth.php');
            $this->assertSame(14, $configuration['password_min_length']);
        } finally {
            $environment->clear('AUTH_PASSWORD_MIN_LENGTH');
            if ($previous !== null) {
                $environment->set('AUTH_PASSWORD_MIN_LENGTH', $previous);
            }
        }
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'password' => 'Original-password-123', 'roles' => ['reader' => ['fr']],
            'locale' => 'fr', 'active' => true, 'is_technical' => false,
        ], $attributes));
    }

    public static function passwordFlows(): iterable
    {
        foreach ([8, 12] as $minimum) {
            foreach (['invitation', 'reset', 'profile', 'admin-create', 'admin-reset'] as $flow) {
                yield $flow.' minimum '.$minimum => [$minimum, $flow];
            }
        }
    }

    #[DataProvider('passwordFlows')]
    public function test_password_writes_enforce_the_configured_boundary(int $minimum, string $flow): void
    {
        config(['auth.password_min_length' => $minimum, 'fortify.mfa_enabled' => false]);
        $user = $this->user();
        $originalHash = $user->password;
        $method = 'post';
        $input = [];

        switch ($flow) {
            case 'invitation':
                $user->forceFill([
                    'invitation_token' => hash('sha256', 'policy-test-token'),
                    'invitation_expires_at' => now()->addHour(), 'invitation_accepted_at' => null,
                ])->save();
                $url = route('invitation.accept', 'policy-test-token');
                $input = ['email' => $user->email];
                break;
            case 'reset':
                $url = route('password.update');
                $input = ['email' => $user->email, 'token' => Password::broker()->createToken($user)];
                break;
            case 'profile':
                $this->actingAs($user);
                $method = 'patch';
                $url = route('profile.password.update');
                $input = ['current_password' => 'Original-password-123'];
                break;
            default:
                $organization = Organisation::create(['name' => 'Policy test', 'code' => 'POLICY', 'active' => true]);
                $admin = $this->user(['roles' => ['admin' => ['fr']], 'organization_id' => $organization->id]);
                $this->actingAs($admin);
                $url = route('admin.users.activate', $user);
                if ($flow === 'admin-create') {
                    $url = route('admin.users.store');
                    $input = [
                        'first_name' => 'Policy', 'last_name' => 'Test', 'email' => 'new-policy@example.test',
                        'organization_id' => $organization->id, 'roles' => ['reader' => ['fr']],
                        'activate_without_email' => true,
                    ];
                }
        }

        $tooShort = str_repeat('a', $minimum - 1);
        $this->{$method}($url, $input + ['password' => $tooShort, 'password_confirmation' => $tooShort])
            ->assertSessionHasErrors('password');
        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertDatabaseMissing('users', ['email' => 'new-policy@example.test']);

        $password = str_repeat('a', $minimum);
        $this->{$method}($url, $input + ['password' => $password, 'password_confirmation' => $password])
            ->assertRedirect()->assertSessionHasNoErrors();
        $updated = $flow === 'admin-create' ? User::where('email', 'new-policy@example.test')->firstOrFail() : $user->fresh();
        $this->assertTrue(Hash::check($password, $updated->password));
    }

    public static function minimumLengths(): array
    {
        return ['eight characters' => [8], 'twelve characters' => [12]];
    }

    #[DataProvider('minimumLengths')]
    public function test_password_forms_display_the_configured_minimum(int $minimum): void
    {
        config(['auth.password_min_length' => $minimum, 'fortify.mfa_enabled' => false]);
        $user = $this->user([
            'roles' => ['admin' => ['fr']], 'invitation_token' => hash('sha256', 'policy-view-token'),
            'invitation_expires_at' => now()->addHour(), 'invitation_accepted_at' => null,
        ]);
        foreach ([route('password.reset', 'policy-view-token'), route('invitation.show', ['token' => 'policy-view-token', 'email' => $user->email])] as $url) {
            $this->get($url)->assertOk()->assertSee('minlength="'.$minimum.'"', false);
        }
        $user->forceFill(['invitation_token' => null, 'invitation_accepted_at' => now()])->save();
        $this->actingAs($user);
        foreach (['fr', 'de', 'it'] as $locale) {
            $user->forceFill(['locale' => $locale])->save();
            $this->withSession(['locale' => $locale]);
            $this->get(route('profile.edit'))->assertOk()->assertSee('minlength="'.$minimum.'"', false)
                ->assertSee(__('ui.change_password_help', ['min' => $minimum], $locale))
                ->assertDontSee(':min');
            $this->get(route('admin.index'))->assertOk()->assertSee('minlength="'.$minimum.'"', false)
                ->assertSee(__('ui.create_manual_activation_help', ['min' => $minimum], $locale))
                ->assertSee(__('ui.manual_activation_help', ['min' => $minimum], $locale))
                ->assertDontSee(':min');
        }
    }

    #[DataProvider('minimumLengths')]
    public function test_installer_enforces_the_configured_minimum(int $minimum): void
    {
        config(['auth.password_min_length' => $minimum]);
        $options = ['--email' => 'policy-admin@example.test', '--name' => 'Policy Administrator'];
        $question = 'Password (at least '.$minimum.' characters; never logged)';
        try {
            $this->artisan('referentiel:install', $options)
                ->expectsQuestion($question, str_repeat('a', $minimum - 1))->run();
            $this->fail('The installer must reject a password below the configured minimum.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }
        $this->assertDatabaseMissing('users', ['email' => $options['--email']]);

        $password = str_repeat('a', $minimum);
        $this->artisan('referentiel:install', $options)->expectsQuestion($question, $password)->assertSuccessful();
        $this->assertTrue(Hash::check($password, User::where('email', $options['--email'])->firstOrFail()->password));
    }
}
