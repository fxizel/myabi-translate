<?php

namespace App\Console\Commands;

use App\Models\Proposal;
use App\Services\OperationLock;
use App\Services\ProposalScreenshots;
use Illuminate\Console\Command;

class PruneProposalScreenshots extends Command
{
    protected $signature = 'proposals:screenshots:prune {--apply : Remove unreferenced screenshot directories older than 24 hours}';

    protected $description = 'Inspect private proposal screenshots; dry run unless --apply is specified';

    public function handle(OperationLock $lock, ProposalScreenshots $screenshots): int
    {
        return $lock->run(function () use ($screenshots) {
            $counts = ['referenced_missing' => 0, 'eligible' => 0, 'removed' => 0, 'skipped' => 0];
            foreach (Proposal::whereNotNull('screenshot_path')->select('id', 'screenshot_path')->lazyById(300) as $proposal) {
                try {
                    if (! is_file($screenshots->absolute($proposal->screenshot_path)) || ! is_file($screenshots->absolute($proposal->screenshot_path, true))) {
                        $counts['referenced_missing']++;
                    }
                } catch (\Throwable) {
                    $counts['referenced_missing']++;
                }
            }
            $root = storage_path('app/private/'.ProposalScreenshots::ROOT);
            if (is_dir($root) && ! is_link($root)) {
                foreach (new \DirectoryIterator($root) as $directory) {
                    if ($directory->isDot()) {
                        continue;
                    }
                    if ($directory->isLink() || ! $directory->isDir() || ! preg_match('/^[0-9a-f]{32}$/D', $directory->getFilename())) {
                        $counts['skipped']++;

                        continue;
                    }
                    $base = ProposalScreenshots::ROOT.'/'.$directory->getFilename().'/image.';
                    if (Proposal::whereIn('screenshot_path', [$base.'png', $base.'jpg'])->exists()) {
                        continue;
                    }
                    $recent = $directory->getMTime() >= now()->subDay()->timestamp;
                    foreach (new \DirectoryIterator($directory->getPathname()) as $file) {
                        if (! $file->isDot() && ($file->isLink() || ! $file->isFile() || $file->getMTime() >= now()->subDay()->timestamp)) {
                            $recent = true;
                        }
                    }
                    if ($recent) {
                        $counts['skipped']++;

                        continue;
                    }
                    $counts['eligible']++;
                    if ($this->option('apply')) {
                        $counts[$screenshots->remove($base.'png') ? 'removed' : 'skipped']++;
                    }
                }
            }
            $this->line(json_encode(['dry_run' => ! $this->option('apply'), ...$counts], JSON_THROW_ON_ERROR));

            return $counts['referenced_missing'] ? self::FAILURE : self::SUCCESS;
        }, true);
    }
}
