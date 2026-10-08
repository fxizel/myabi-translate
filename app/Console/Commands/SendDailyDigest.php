<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\DailyDigest;
use App\Services\AuditService;
use App\Services\DailyDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class SendDailyDigest extends Command
{
    protected $signature = 'notifications:daily {--dry-run : Count recipients without sending messages}';

    protected $description = 'Deliver the daily, optional, role-scoped translation digest';

    public function handle(DailyDigestService $digest): int
    {
        $lock = Cache::lock('notifications-daily', 600);
        if (! $lock->get()) {
            $this->warn('A daily digest run is already active.');

            return self::SUCCESS;
        }
        $delivered = 0;
        $failed = 0;
        try {
            $day = now('Europe/Zurich')->startOfDay()->utc();
            $users = User::where('active', true)->where('is_technical', false)->where('notifications_enabled', true)
                ->whereNull('invitation_token')->whereHas('organization', fn ($query) => $query->where('active', true))
                ->where(fn ($query) => $query->whereNull('last_digest_at')->orWhere('last_digest_at', '<', $day));
            foreach ($users->lazyById(100) as $user) {
                $countedAt = now()->startOfSecond();
                $counts = $digest->counts($user, $countedAt);
                if ($counts === []) {
                    continue;
                }
                if (! $this->option('dry-run')) {
                    try {
                        DB::transaction(function () use ($user, $counts, $countedAt) {
                            $user->notifyNow(new DailyDigest($counts, $user->locale));
                            $user->forceFill(['last_digest_at' => $countedAt])->save();
                            app(AuditService::class)->record('notification.daily_sent', $user, [], ['counts' => $counts], $user);
                        });
                    } catch (TransportExceptionInterface) {
                        $failed++;
                        app(AuditService::class)->record('notification.daily_failed', $user, [], ['retryable' => true], $user);
                        $this->error('Daily digest delivery failed for account '.$user->id.'. Retry remains available.');

                        continue;
                    }
                }
                $delivered++;
            }
        } finally {
            $lock->release();
        }
        $this->info($delivered.' daily digest recipient(s)'.($this->option('dry-run') ? ' (dry run).' : ' delivered.'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
