<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneBrowserExtensionFeedback extends Command
{
    protected $signature = 'extension:prune-feedback';

    protected $description = 'Supprimer les retours de correspondance expirés sans modifier le journal d’audit';

    public function handle(): int
    {
        $days = max(1, (int) config('browser-extension.feedback_retention_days', 90));
        $count = DB::table('browser_extension_feedback')->where('created_at', '<', now()->subDays($days))->delete();
        $this->info('Retours supprimés : '.$count);

        return self::SUCCESS;
    }
}
