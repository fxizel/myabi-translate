<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrowserExtensionProfileRolesTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roleFormats')]
    public function test_profile_and_token_management_accept_both_authorized_role_formats(array $roles, array $expected): void
    {
        config(['fortify.mfa_enabled' => false]);
        $user = User::factory()->create(['roles' => $roles, 'active' => true, 'locale' => 'fr']);
        $response = $this->actingAs($user)->get('/profile')->assertOk()
            ->assertSee(route('profile.extension-tokens.store'), false);
        foreach ($expected as $role) {
            $response->assertSee(__('ui.'.$role));
        }
    }

    public static function roleFormats(): array
    {
        return [
            'flat reader' => [['reader'], ['reader']],
            'flat manager' => [['manager'], ['manager']],
            'role language mapping' => [['manager' => ['de', 'fr'], 'translator' => ['it']], ['manager', 'translator']],
        ];
    }
}
