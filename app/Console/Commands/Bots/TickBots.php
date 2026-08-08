<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Bots\Support\ReadsScalarOptions;
use OGame\Enums\BotPersona;
use OGame\Jobs\BotTickJob;
use OGame\Models\BotProfile;
use Throwable;

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

        // A paused server keeps its bots but stops giving them turns. Unlike BOTS_ENABLED this
        // needs no deploy, which is what makes it usable when something is going wrong.
        if (Cache::get('bots:paused', false)) {
            return self::SUCCESS;
        }

        // Skip the sweep entirely when the worker is already behind. Queuing more turns would
        // only make them staler by the time they run, and a backlog that never drains is worse
        // than a few missed sessions.
        $backlog = $this->queueBacklog();
        $maxBacklog = (int) config('bots.tick.max_queue_backlog', 500);

        if ($maxBacklog > 0 && $backlog > $maxBacklog) {
            Log::warning(sprintf('Skipping bot sweep: %d jobs already queued (limit %d).', $backlog, $maxBacklog));

            return self::SUCCESS;
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
        $startedAt = microtime(true);

        foreach ($due as $userId) {
            $job = new BotTickJob((int) $userId);

            if ($this->option('sync')) {
                dispatch_sync($job);
            } else {
                dispatch($job)->onQueue($queue);
            }
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        // Kept where an operator can find it. A sweep that starts taking seconds, or that keeps
        // hitting the limit, is the first sign the population has outgrown the worker.
        Cache::put('bots:last_sweep', [
            'at' => Date::now()->toDateTimeString(),
            'dispatched' => $due->count(),
            'backlog' => $backlog,
            'elapsed_ms' => $elapsedMs,
        ], 3600);

        $this->line(sprintf('Dispatched %d bot turn(s) in %dms.', $due->count(), $elapsedMs), 'info', 'v');

        return self::SUCCESS;
    }

    /**
     * How many bot turns are already waiting to run.
     */
    private function queueBacklog(): int
    {
        // Only the database queue driver can be inspected cheaply; anything else reports zero
        // rather than guessing, which disables backpressure instead of misapplying it.
        if (config('queue.default') !== 'database') {
            return 0;
        }

        try {
            return (int) DB::table((string) config('queue.connections.database.table', 'jobs'))
                ->where('queue', (string) config('bots.tick.queue', 'bots'))
                ->count();
        } catch (Throwable) {
            return 0;
        }
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
