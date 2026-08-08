<?php

namespace OGame\Bots\Perception;

use Illuminate\Support\Facades\Date;
use OGame\Models\BotMemory;

/**
 * Records how a bot feels about the people it runs into.
 *
 * Because bots never send messages, this table is the whole of their social life. Every
 * relationship a bot has is expressed through what it does: who it attacks back, whose alliance
 * application it rejects, whose buddy request it ignores. Without a memory those decisions have
 * nothing to be based on, and a bot that is farmed every day by the same player and never once
 * responds reads as scenery rather than an opponent.
 *
 * Attitude runs from -100 to 100 and moves in both directions, so a grudge can cool over time
 * and an enemy can become a neutral again.
 */
class BotMemoryService
{
    /**
     * Attitude change per event.
     *
     * Being attacked hurts far more than being scouted, and losing resources hurts in proportion
     * to how much was taken, which is handled separately in recordAttackSuffered().
     */
    private const ATTITUDE_SPIED = -4;

    private const ATTITUDE_ATTACKED = -25;

    private const ATTITUDE_ATTACKED_BY_US = -5;

    /**
     * Get the bot's memory of a player, creating a neutral one if they have never met.
     */
    public function remember(int $botUserId, int $otherUserId): BotMemory
    {
        return BotMemory::firstOrCreate(
            ['bot_user_id' => $botUserId, 'other_user_id' => $otherUserId],
            ['attitude' => 0],
        );
    }

    /**
     * Get the bot's attitude towards a player without creating a record.
     *
     * Returns 0 for a stranger, which is what a bot should feel about someone it has never
     * interacted with.
     */
    public function attitudeTowards(int $botUserId, int $otherUserId): int
    {
        return (int) (BotMemory::where('bot_user_id', $botUserId)
            ->where('other_user_id', $otherUserId)
            ->value('attitude') ?? 0);
    }

    /**
     * Record that someone attacked this bot.
     *
     * The attitude hit scales with what it cost: a raid that took a few thousand is an
     * irritation, one that cost a fleet is a reason to remember the name for weeks.
     */
    public function recordAttackSuffered(int $botUserId, int $attackerUserId, float $resourcesLost): void
    {
        $memory = $this->remember($botUserId, $attackerUserId);

        $severity = (int) round(min(40, $resourcesLost / 25000));

        $memory->attacked_us_count++;
        $memory->resources_lost_to += $resourcesLost;
        $memory->adjustAttitude(self::ATTITUDE_ATTACKED - $severity);
        $memory->save();
    }

    /**
     * Record that this bot attacked someone.
     *
     * A bot dislikes its own victims a little: it keeps raiding a profitable target rather than
     * feeling guilty about it, and it makes the relationship mutually hostile, which is how these
     * things actually go.
     */
    public function recordAttackMade(int $botUserId, int $targetUserId, float $resourcesGained): void
    {
        $memory = $this->remember($botUserId, $targetUserId);

        $memory->we_attacked_count++;
        $memory->resources_taken_from += $resourcesGained;
        $memory->adjustAttitude(self::ATTITUDE_ATTACKED_BY_US);
        $memory->save();
    }

    /**
     * Record that someone scouted this bot.
     *
     * Being probed is usually the prelude to being attacked, so it sours the relationship a
     * little and, more usefully, marks the player as someone worth watching.
     */
    public function recordSpiedOn(int $botUserId, int $spyUserId): void
    {
        $memory = $this->remember($botUserId, $spyUserId);

        $memory->spied_us_count++;
        $memory->adjustAttitude(self::ATTITUDE_SPIED);
        $memory->save();
    }

    /**
     * Let old grudges fade.
     *
     * Attitude decays towards neutral over time, so a player who wronged a bot months ago and
     * has left it alone since is eventually forgiven. Without this every bot accumulates enemies
     * forever and the universe calcifies into permanent feuds.
     *
     * @return int Number of memories that moved.
     */
    public function decayGrudges(int $botUserId, int $daysSinceInteraction = 14): int
    {
        $stale = BotMemory::where('bot_user_id', $botUserId)
            ->where('attitude', '!=', 0)
            ->where(function ($query) use ($daysSinceInteraction) {
                $query->whereNull('last_interaction_at')
                    ->orWhere('last_interaction_at', '<', Date::now()->subDays($daysSinceInteraction));
            })
            ->get();

        foreach ($stale as $memory) {
            // Move one step towards neutral, from whichever side it is on.
            $step = $memory->attitude > 0 ? -5 : 5;
            $memory->attitude = abs($memory->attitude) <= 5 ? 0 : $memory->attitude + $step;
            $memory->save();
        }

        return $stale->count();
    }

    /**
     * Get the players this bot holds a grudge against, worst first.
     *
     * @return array<int, int> Other user IDs.
     */
    public function enemies(int $botUserId, int $limit = 10): array
    {
        return BotMemory::where('bot_user_id', $botUserId)
            ->where('attitude', '<=', BotMemory::HOSTILE_THRESHOLD)
            ->orderBy('attitude')
            ->limit($limit)
            ->pluck('other_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
