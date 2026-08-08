<?php

namespace OGame\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use OGame\Bots\Activity\ActivityScheduler;
use OGame\Bots\Brain\BotBrain;
use OGame\Bots\Perception\BotBattleObserver;
use OGame\Bots\Perception\BotIntelWriter;
use OGame\Bots\Perception\BotMemoryService;
use OGame\Bots\Support\BotSynchroniser;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BotProfile;
use Throwable;

/**
 * Runs one bot's turn.
 *
 * Dispatched by the tick command onto the "bots" queue, which the worker drains only after the
 * default queue, so a large NPC population can never delay ordinary game jobs.
 */
class BotTickJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt only.
     *
     * A retried bot turn would replay decisions that already spent resources, and a bot missing
     * one turn is invisible: it simply looks like a player who did not click anything. Failing
     * quietly is the right trade here.
     */
    public int $tries = 1;

    public function __construct(private readonly int $botUserId)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(
        PlayerServiceFactory $playerServiceFactory,
        BotSynchroniser $synchroniser,
        BotBrain $brain,
        ActivityScheduler $scheduler,
        BotIntelWriter $intelWriter,
        BotBattleObserver $battleObserver,
        BotMemoryService $memoryService,
    ): void {
        $lockSeconds = (int) config('bots.tick.lock_seconds', 120);

        // Never let two turns for the same bot overlap: the second would act on stale resource
        // figures read before the first one spent them.
        $lock = Cache::lock('bot:tick:' . $this->botUserId, $lockSeconds);

        if (!$lock->get()) {
            return;
        }

        try {
            $profile = BotProfile::where('user_id', $this->botUserId)->first();

            if ($profile === null || !$profile->isTickable()) {
                return;
            }

            $player = $playerServiceFactory->make($this->botUserId, true);

            // Bring the account up to date first. Without this the bot would decide using
            // resource figures from whenever it last acted, and its finished buildings would
            // not exist yet.
            $synchroniser->sync($player, $profile);

            // Read any espionage reports that arrived since the last turn. This must happen
            // before the brain runs, because target selection reads bot_intel and nothing else.
            $intelWriter->absorbReports($profile);

            // Battle reports tell the bot who hurt it and what the fight revealed. Both have to
            // land before the brain decides anything, because targeting reads them.
            $battleObserver->absorbReports($profile);

            // Old grudges cool off, so the universe does not calcify into permanent feuds.
            $memoryService->decayGrudges($profile->user_id);

            $actionsTaken = 0;

            // A bot only acts inside its own waking hours. Outside them the sync above still
            // ran, so its queues and fleets progressed, exactly like an offline human.
            if ($profile->isAwake() && config('bots.enabled')) {
                $budget = $scheduler->rollSessionActions($profile);
                $actionsTaken = $brain->run($player, $profile, $budget);
            }

            $profile->last_tick_at = Date::now();
            $profile->next_action_at = $scheduler->nextActionAt($profile, $this->remainingBudget($actionsTaken));
            $profile->save();
        } catch (Throwable $e) {
            // Push the bot forward regardless, so one bad turn cannot wedge it into ticking
            // every minute forever.
            $this->deferAfterFailure();

            Log::warning('Bot tick failed for user ' . $this->botUserId . ': ' . $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * How many actions are left in this session.
     *
     * The brain runs a whole session in one job, so a completed turn always ends the session.
     * The hook exists for Phase 5, where long sessions may be split across jobs.
     */
    private function remainingBudget(int $actionsTaken): int
    {
        return 0;
    }

    /**
     * Move a failed bot's next action far enough out that it does not retry in a tight loop.
     */
    private function deferAfterFailure(): void
    {
        BotProfile::where('user_id', $this->botUserId)->update([
            'next_action_at' => Date::now()->addMinutes(random_int(30, 120)),
            'last_tick_at' => Date::now(),
        ]);
    }
}
