<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\DailyDigest;
use App\Services\DailyDigestService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class SecurityDailyDigestTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $roles, array $attributes = []): User
    {
        $organization = Organisation::create(['name' => 'Test police', 'code' => 'P'.bin2hex(random_bytes(4))]);

        return User::factory()->create(array_merge(['organization_id' => $organization->id, 'roles' => $roles], $attributes));
    }

    private function proposal(User $author, string $language = 'fr', string $status = 'pending', array $attributes = []): Proposal
    {
        return Proposal::create(array_merge([
            'term_id' => 1, 'attribute' => 'label', 'language' => $language, 'value' => 'Example',
            'value_hash' => hash('sha256', 'Example'), 'status' => $status,
            'author_id' => $author->id, 'organization_id' => $author->organization_id,
            'anomalies' => [], 'edit_history' => [],
        ], $attributes));
    }

    public function test_daily_digest_scopes_languages_and_decisions_and_sends_only_once_per_day(): void
    {
        Notification::fake();
        $translator = $this->user(['translator' => ['fr']]);
        $validator = $this->user(['validator' => ['fr']]);
        $optedOut = $this->user(['validator' => ['fr']], ['notifications_enabled' => false]);
        $disabled = $this->user(['validator' => ['fr']], ['active' => false]);
        $this->proposal($translator, attributes: ['updated_at' => now()->subDays(31)]);
        $this->proposal($translator, 'it');
        $this->proposal($translator, 'fr', 'validated', ['decided_at' => now()]);
        $this->proposal($validator, 'fr', 'rejected', ['decided_at' => now()]);
        $this->artisan('notifications:daily')->assertSuccessful();
        Notification::assertSentTo($validator, DailyDigest::class, fn ($mail) => $mail->counts === ['pending' => 1, 'overdue' => 1, 'rejected' => 1]);
        Notification::assertSentTo($translator, DailyDigest::class, fn ($mail) => $mail->counts === ['validated' => 1]);
        Notification::assertNotSentTo($optedOut, DailyDigest::class);
        Notification::assertNotSentTo($disabled, DailyDigest::class);
        $this->artisan('notifications:daily')->assertSuccessful();
        Notification::assertSentToTimes($validator, DailyDigest::class, 1);
        Notification::assertSentToTimes($translator, DailyDigest::class, 1);
        $this->assertNotNull($validator->fresh()->last_digest_at);
    }

    public function test_dry_run_does_not_queue_messages_or_change_delivery_timestamp(): void
    {
        Notification::fake();
        $validator = $this->user(['validator' => ['fr']]);
        $this->proposal($validator);
        $this->artisan('notifications:daily --dry-run')->assertSuccessful();
        Notification::assertNothingSent();
        $this->assertNull($validator->fresh()->last_digest_at);
    }

    public function test_digest_rechecks_user_opt_out_before_delivery(): void
    {
        $validator = $this->user(['validator' => ['fr']]);
        $notification = new DailyDigest(['pending' => 2]);
        $this->assertTrue($notification->shouldSend($validator, 'mail'));
        User::whereKey($validator->id)->update(['notifications_enabled' => false]);
        $this->assertFalse($notification->shouldSend($validator, 'mail'));
    }

    public function test_manager_digest_uses_bounded_database_counts_for_review_flags(): void
    {
        $manager = $this->user(['manager' => ['de', 'fr', 'it']]);
        $this->assertSame([], app(DailyDigestService::class)->counts($manager));
        $this->proposal($manager, 'it');
        $this->assertSame(['pending' => 1], app(DailyDigestService::class)->counts($manager));
    }

    public function test_smtp_failure_preserves_delivery_watermark_and_same_day_retry_succeeds(): void
    {
        $validator = $this->user(['validator' => ['fr']], ['last_digest_at' => now()->subDay()]);
        $this->proposal($validator);
        $before = $validator->fresh()->last_digest_at->toDateTimeString();
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new TransportException('Synthetic SMTP outage'));
        Mail::shouldReceive('mailer')->once()->andReturn($mailer);

        $this->artisan('notifications:daily')->assertExitCode(1);
        $this->assertSame($before, $validator->fresh()->last_digest_at->toDateTimeString());
        $this->assertDatabaseMissing('audit_events', ['user_id' => $validator->id, 'action' => 'notification.daily_sent']);
        $this->assertDatabaseHas('audit_events', ['user_id' => $validator->id, 'action' => 'notification.daily_failed']);

        Notification::fake();
        $this->artisan('notifications:daily')->assertSuccessful();
        Notification::assertSentToTimes($validator, DailyDigest::class, 1);
        $this->assertTrue($validator->fresh()->last_digest_at->isToday());
        $this->assertDatabaseHas('audit_events', ['user_id' => $validator->id, 'action' => 'notification.daily_sent']);
        $this->artisan('notifications:daily')->assertSuccessful();
        Notification::assertSentToTimes($validator, DailyDigest::class, 1);
    }

    public function test_first_delivery_keeps_decisions_older_than_24_hours_after_an_outage(): void
    {
        Notification::fake();
        $translator = $this->user(['translator' => ['fr']], ['created_at' => now()->subDays(5)]);
        $this->proposal($translator, 'fr', 'validated', ['decided_at' => now()->subDays(3)]);
        $this->artisan('notifications:daily')->assertSuccessful();
        Notification::assertSentTo($translator, DailyDigest::class, fn ($mail) => $mail->counts === ['validated' => 1]);
    }
}
