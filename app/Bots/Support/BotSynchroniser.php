<?php

namespace OGame\Bots\Support;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\BotProfile;
use OGame\Models\User;
use OGame\Services\PlayerService;
use RuntimeException;
use Throwable;

/**
 * Advances a bot's game state outside of an HTTP request.
 *
 * OGameX has no global tick: OGame\Http\Middleware\GlobalGame advances a player's research
 * queue, planets and fleet missions only while that player is browsing. Bots never browse, so
 * this class does the same work from the console and the queue worker.
 *
 * It deliberately does not call PlayerService::update(), because that method also writes
 * request()->ip() and stamps user->time on every call. Both are wrong for a bot:
 *
 *  - request()->ip() has no meaning under a queue worker, and writing the same value for every
 *    bot would group them all together in the admin panel's shared-IP detection.
 *  - user->time is the activity clock behind isInactive() / isLongInactive() and therefore
 *    behind the (i) and (I) markers in the galaxy view. Stamping it on every tick would make
 *    every bot look permanently online, which is the fastest possible way to make the whole
 *    population read as fake.
 *
 * PlayerService::updateResearchQueue() is public, so the useful half of update() is reused
 * directly and the two side effects are handled here instead.
 */
class BotSynchroniser
{
    /**
     * Advance a bot to the present moment.
     *
     * Mirrors the order used by GlobalGame: research queue first, then each planet (building
     * queue, resources, unit queue, production, storage), then arrived fleet missions.
     *
     * Planet moves are intentionally not processed here. PlanetMoveService::processDueMoves()
     * is global rather than player-scoped, so running it once per bot would repeat the same work
     * for the whole server on every tick. Bots do not move planets before Phase 2; when they do,
     * it belongs in the tick command's sweep, once per sweep.
     *
     * @param PlayerService $player The bot's player service.
     * @param BotProfile $profile The bot's profile, used to decide whether to stamp activity.
     * @throws Throwable
     */
    public function sync(PlayerService $player, BotProfile $profile): void
    {
        $this->syncPlayer($player, $profile);

        // Update every planet, not just a "current" one: a bot has no browser and therefore no
        // current planet in the sense the middleware means, and its colonies must keep producing.
        foreach ($player->planets->all() as $planet) {
            $planet->update();
        }

        $player->updateFleetMissions();
    }

    /**
     * Update the bot's user record and research queue.
     *
     * @throws Throwable
     */
    private function syncPlayer(PlayerService $player, BotProfile $profile): void
    {
        DB::transaction(function () use ($player, $profile) {
            // Same locking approach as PlayerService::update(): take the row lock first so a
            // concurrent human page load touching this account cannot interleave with the tick.
            $playerLock = User::where('id', $player->getId())
                ->lockForUpdate()
                ->first();

            if ($playerLock === null) {
                throw new RuntimeException('Could not acquire bot player update lock for user ' . $player->getId() . '.');
            }

            $player->updateResearchQueue(false);

            $user = $player->getUser();

            // Only stamp activity while the bot is inside its own waking hours. A sleeping bot,
            // and a ghost that stopped playing months ago, must be allowed to drift into the
            // (i) and (I) inactive markers exactly like an absent human would.
            if ($profile->isAwake()) {
                $user->time = (string) Date::now()->timestamp;
            }

            $user->last_ip = $this->syntheticIp($player->getId());

            $user->save();
        });
    }

    /**
     * Build a stable synthetic IP address for a bot.
     *
     * Derived from the user id so it never changes, and taken from a private RFC 1918 range so
     * it cannot collide with a real player's public address on a live server. Giving each bot
     * its own address keeps them out of the admin panel's shared-IP grouping, which flags
     * clusters of accounts that share one address.
     */
    public function syntheticIp(int $userId): string
    {
        $prefix = (string) config('bots.ip_prefix', '10.99');

        // Two octets give 65,536 distinct addresses, comfortably more than any bot population.
        $third = intdiv($userId, 256) % 256;
        $fourth = $userId % 256;

        return $prefix . '.' . $third . '.' . $fourth;
    }
}
