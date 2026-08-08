<?php

namespace OGame\Bots\Scale;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use OGame\Enums\BotLod;
use OGame\Models\BotProfile;
use OGame\Models\FleetMission;
use OGame\Models\Planet;

/**
 * Decides how closely each bot needs to be simulated.
 *
 * Simulating 200 bots at full fidelity every minute is mostly wasted work: almost none of them
 * are anywhere a human will look today. The level of detail decides **cadence**, never
 * correctness — an Abstract bot is simulated exactly the same way, just far less often, and
 * because PlanetService catches up from `time_last_update` whenever it does run, its state is
 * always right when anyone actually looks at it.
 *
 * That distinction is what makes this safe. Nothing here changes what a bot's account contains,
 * only how often the bot gets a turn.
 */
class BotLodClassifier
{
    /**
     * How many systems either side of a human planet counts as "a human might look here".
     */
    private const OBSERVATION_RADIUS = 20;

    /**
     * How long the map of human-occupied systems is cached.
     *
     * Humans do not colonise often, and the classification runs for every due bot on every sweep,
     * so this is the difference between one query per sweep and one per bot.
     */
    private const HUMAN_MAP_TTL = 300;

    /**
     * Work out the level of detail for a bot.
     */
    public function classify(BotProfile $profile): BotLod
    {
        // Ghosts stopped playing forever; nothing about them ever changes.
        if ($profile->persona->isDormant() || !$profile->enabled) {
            return BotLod::Dormant;
        }

        // A bot with a fleet in the air must stay fully simulated regardless of where it lives:
        // that mission has to arrive on time, and it may be arriving at a human.
        if ($this->hasActiveMission($profile)) {
            return BotLod::Full;
        }

        // Someone interacted with it recently, so a human is paying attention to this account.
        if ($this->recentlyInvolvedWithHuman($profile)) {
            return BotLod::Full;
        }

        return $this->isNearHumans($profile) ? BotLod::Full : BotLod::Abstract;
    }

    /**
     * Classify a bot and persist the result if it changed.
     */
    public function refresh(BotProfile $profile): BotLod
    {
        $lod = $this->classify($profile);

        if ($profile->lod !== $lod) {
            $profile->lod = $lod;
        }

        return $lod;
    }

    /**
     * Whether any of this bot's fleets are currently underway.
     */
    private function hasActiveMission(BotProfile $profile): bool
    {
        return FleetMission::query()
            ->where('user_id', $profile->user_id)
            ->where('processed', 0)
            ->exists();
    }

    /**
     * Whether a human has recently sent something at this bot, or it at them.
     */
    private function recentlyInvolvedWithHuman(BotProfile $profile): bool
    {
        $planetIds = Planet::where('user_id', $profile->user_id)->pluck('id');

        if ($planetIds->isEmpty()) {
            return false;
        }

        return FleetMission::query()
            ->where(function ($query) use ($planetIds) {
                $query->whereIn('planet_id_to', $planetIds)
                    ->orWhereIn('planet_id_from', $planetIds);
            })
            ->where('time_arrival', '>=', Date::now()->subDay()->timestamp)
            ->exists();
    }

    /**
     * Whether the bot lives close enough to a human that one might look at it.
     */
    private function isNearHumans(BotProfile $profile): bool
    {
        $humanSystems = $this->humanSystems();

        if ($humanSystems === []) {
            // A server with no human players at all: nothing needs full fidelity.
            return false;
        }

        $planets = Planet::where('user_id', $profile->user_id)->get(['galaxy', 'system']);

        foreach ($planets as $planet) {
            foreach ($humanSystems as $key) {
                [$galaxy, $system] = explode(':', $key);

                if ((int) $galaxy !== $planet->galaxy) {
                    continue;
                }

                if (abs((int) $system - $planet->system) <= self::OBSERVATION_RADIUS) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every system a human player has a planet in, as "galaxy:system" keys.
     *
     * @return array<int, string>
     */
    public function humanSystems(): array
    {
        /** @var array<int, string> $systems */
        $systems = Cache::remember('bots:human_systems', self::HUMAN_MAP_TTL, function () {
            $botUserIds = BotProfile::pluck('user_id');

            return Planet::query()
                ->whereNotNull('user_id')
                ->whereNotIn('user_id', $botUserIds)
                ->get(['galaxy', 'system'])
                ->map(fn (Planet $planet) => $planet->galaxy . ':' . $planet->system)
                ->unique()
                ->values()
                ->all();
        });

        return $systems;
    }
}
