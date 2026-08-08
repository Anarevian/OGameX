<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use OGame\Models\BotActionLog;

/**
 * Keeps the NPC decision log bounded.
 *
 * Every bot writes a row per decision, so a 200-bot server accumulates tens of thousands of
 * rows a week. The log is a debugging and tuning surface, not a permanent record, so anything
 * older than the configured retention window is dropped.
 */
#[Description('Delete NPC decision log entries older than the configured retention window.')]
#[Signature('ogamex:bots:prune-log
                            {--days= : Override the configured retention window.}')]
class PruneBotActionLog extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $daysOption = $this->option('days');
        $days = is_string($daysOption) && $daysOption !== ''
            ? (int) $daysOption
            : (int) config('bots.action_log_retention_days', 14);

        if ($days < 1) {
            $this->error('Retention window must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = Date::now()->subDays($days);

        // Delete in chunks so a long-neglected log does not lock the table in one statement.
        $total = 0;
        do {
            $deleted = BotActionLog::where('created_at', '<', $cutoff)->limit(5000)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        if ($total > 0) {
            $this->info(sprintf('Pruned %d NPC decision log entries older than %d days.', $total, $days));
        }

        return self::SUCCESS;
    }
}
