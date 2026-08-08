<?php

namespace OGame\Bots\Scale;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Support\BotSynchroniser;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BotProfile;
use OGame\Models\Planet;
use Throwable;

/**
 * Brings a bot up to date the moment a human looks at it.
 *
 * Abstract bots take a turn only every few hours, so between turns their planets carry stale
 * resource figures — the same way a human's planet does between page loads. That is invisible
 * until someone looks, and then it matters: a galaxy view or an espionage report should show
 * what is actually there, not what was there four hours ago.
 *
 * This is the same lazy-update pattern the game already uses for humans in the GlobalGame
 * middleware, applied from the observer's side instead of the owner's.
 */
class BotMaterialiser
{
    /**
     * How long after materialising a bot to leave it alone.
     *
     * Without this, one human scrolling through the galaxy would re-sync the same accounts on
     * every page load.
     */
    private const COOLDOWN_SECONDS = 60;

    /**
     * How recently a bot must have taken a turn to count as already current.
     *
     * A bot that ticked minutes ago has nothing worth catching up, and skipping it is the
     * difference between a galaxy page load costing milliseconds and costing most of a second.
     */
    private const FRESH_MINUTES = 10;

    /**
     * The most bots to synchronise in one request.
     *
     * This runs inside a human's page load, so it has a hard budget. A system holding more stale
     * bots than this gets the rest on the next view, by which time the tick has probably caught
     * them anyway.
     */
    private const MAX_PER_REQUEST = 4;

    public function __construct(
        private readonly PlayerServiceFactory $playerServiceFactory,
        private readonly BotSynchroniser $synchroniser,
    ) {
    }

    /**
     * Bring every bot owning a planet in the given system up to date.
     *
     * Called from the galaxy view, which is where a human is most likely to be looking at
     * accounts nobody has touched in hours.
     */
    public function materialiseSystem(int $galaxy, int $system): void
    {
        if (!config('bots.enabled')) {
            return;
        }

        $userIds = Planet::query()
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique();

        if ($userIds->isEmpty()) {
            return;
        }

        // Only bots that are actually behind. In a healthy server most will have ticked
        // recently, so this usually finds nothing and costs one indexed query.
        $stale = BotProfile::query()
            ->whereIn('user_id', $userIds)
            ->where('enabled', true)
            ->where(function ($query) {
                $query->whereNull('last_tick_at')
                    ->orWhere('last_tick_at', '<', Date::now()->subMinutes(self::FRESH_MINUTES));
            })
            ->limit(self::MAX_PER_REQUEST)
            ->pluck('user_id');

        foreach ($stale as $userId) {
            $this->materialise((int) $userId);
        }
    }

    /**
     * Bring a single bot up to date.
     *
     * Deliberately silent on failure. This runs inside a human's page request, and a bot that
     * cannot be synchronised must never turn someone else's galaxy view into an error page.
     */
    public function materialise(int $botUserId): bool
    {
        $lock = 'bots:materialised:' . $botUserId;

        if (Cache::has($lock)) {
            return false;
        }

        Cache::put($lock, true, self::COOLDOWN_SECONDS);

        try {
            $profile = BotProfile::where('user_id', $botUserId)->first();

            if ($profile === null || !$profile->isTickable()) {
                return false;
            }

            $player = $this->playerServiceFactory->make($botUserId, true);
            $this->synchroniser->sync($player, $profile);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
