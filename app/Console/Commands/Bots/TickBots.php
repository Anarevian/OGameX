<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Support\ReadsScalarOptions;
use OGame\Enums\BotPersona;
use OGame\Jobs\BotTickJob;
use OGame\Models\BotProfile;

/**
 * Wakes up whichever bots are due to act.
 *
 * The game has no global tick: OGame\Http\Middleware\GlobalGame only advances the player who is
 * currently browsing. This command is the bots' equivalent, and is the reason they play at all.
 *
 * It does no work itself beyond selecting due bots and dispatching one job each, so a sweep
 * stays cheap even with a large population and one slow bot cannot hold up the rest.
 */
#[Description('Dispatch a turn for every NPC (bot) that is due to act.')]
#[Signature('ogamex:bots:tick
                            {--limit= : Maximum number of bots to dispatch this sweep.}
                            {--sync : Run the turns immediately instead of queueing them.}
                            {--user= : Tick one specific bot user id, ignoring its schedule.}')]
class TickBots extends Command
{
    use ReadsScalarOptions;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!config('bots.enabled') && $this->intOption('user') === null) {
            // Silent by default: this runs every minute, and a disabled server should not fill
            // the scheduler log with notices.
            $this->line('Bots are disabled (BOTS_ENABLED=false). Nothing to do.', 'comment', 'v');

            return self::SUCCESS;
        }

        $userOption = $this->intOption('user');
        if ($userOption !== null) {
            return $this->tickSingle($userOption);
        }

        $limit = $this->intOption('limit') ?? (int) config('bots.tick.max_bots_per_sweep', 60);

        $due = BotProfile::query()
            ->where('enabled', true)
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', Date::now())
            // Ghosts never act; excluding them here keeps them off the queue entirely.
            ->where('persona', '!=', BotPersona::Ghost->value)
            // Oldest due first, so a bot that has been waiting longest is not starved by a
            // sweep limit that keeps picking up newer arrivals.
            ->orderBy('next_action_at')
            ->limit($limit)
            ->pluck('user_id');

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $queue = (string) config('bots.tick.queue', 'bots');

        foreach ($due as $userId) {
            $job = new BotTickJob((int) $userId);

            if ($this->option('sync')) {
                dispatch_sync($job);
            } else {
                dispatch($job)->onQueue($queue);
            }
        }

        $this->line(sprintf('Dispatched %d bot turn(s).', $due->count()), 'info', 'v');

        return self::SUCCESS;
    }

    /**
     * Tick one bot immediately, regardless of its schedule.
     *
     * Used from the admin panel's "force tick" and when debugging a specific account.
     */
    private function tickSingle(int $userId): int
    {
        $profile = BotProfile::where('user_id', $userId)->first();

        if ($profile === null) {
            $this->error(sprintf('User %d is not a bot.', $userId));

            return self::FAILURE;
        }

        dispatch_sync(new BotTickJob($userId));

        $profile->refresh();
        $this->info(sprintf(
            'Ticked bot %d (%s). Stance: %s. Next action: %s.',
            $userId,
            $profile->persona->value,
            $profile->state['stance'] ?? 'unknown',
            $profile->next_action_at?->toDateTimeString() ?? 'never',
        ));

        return self::SUCCESS;
    }
}
