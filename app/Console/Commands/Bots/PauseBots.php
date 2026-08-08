<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Stops or resumes NPC turns without a deploy.
 *
 * BOTS_ENABLED is the permanent switch and needs a config change; this is the one to reach for
 * when something is going wrong right now. Bots stay exactly where they are, visible in the
 * galaxy and the highscores, and simply stop taking turns until resumed.
 */
#[Description('Pause or resume NPC (bot) turns.')]
#[Signature('ogamex:bots:pause
                            {--resume : Resume ticking instead of pausing.}
                            {--status : Report whether bots are currently paused.}')]
class PauseBots extends Command
{
    /**
     * How long a pause lasts before it lifts on its own.
     *
     * Deliberately finite: a pause set during an incident and then forgotten would otherwise
     * leave the universe frozen with no obvious cause.
     */
    private const PAUSE_TTL_HOURS = 24;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('status')) {
            $paused = (bool) Cache::get('bots:paused', false);
            $this->info($paused ? 'Bots are paused.' : 'Bots are running.');

            $sweep = Cache::get('bots:last_sweep');
            if (is_array($sweep)) {
                $this->line(sprintf(
                    'Last sweep %s: %d dispatched, %d queued, %dms.',
                    (string) ($sweep['at'] ?? '?'),
                    (int) ($sweep['dispatched'] ?? 0),
                    (int) ($sweep['backlog'] ?? 0),
                    (int) ($sweep['elapsed_ms'] ?? 0),
                ));
            }

            return self::SUCCESS;
        }

        if ($this->option('resume')) {
            Cache::forget('bots:paused');
            $this->info('Bots resumed.');

            return self::SUCCESS;
        }

        Cache::put('bots:paused', true, now()->addHours(self::PAUSE_TTL_HOURS));
        $this->info(sprintf('Bots paused. This lifts automatically in %d hours, or run --resume.', self::PAUSE_TTL_HOURS));

        return self::SUCCESS;
    }
}
