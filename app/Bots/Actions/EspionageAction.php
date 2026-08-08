<?php

namespace OGame\Bots\Actions;

use Exception;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Brain\ActionCandidate;
use OGame\Bots\Brain\BotContext;
use OGame\Bots\Support\BotFleetService;
use OGame\Models\BotIntel;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Services\PlanetService;

/**
 * Sends espionage probes at neighbouring planets.
 *
 * This is the action that gives bots a fog of war. Nothing else may read the planets table to
 * decide who to attack: raiding reads bot_intel, and bot_intel is only ever written by a real
 * espionage mission arriving or by a galaxy scan. A bot therefore knows exactly as much as a
 * player who did the same scouting, no more, and its intel goes stale at the same rate.
 *
 * Choosing *where* to scout is the one place a bot is allowed to look at the world directly,
 * because that mirrors the galaxy view, which shows a human every occupied slot in a system for
 * free. Only the coordinate and the fact that it is occupied come from that lookup — never the
 * defences, the fleet or the resources, which are exactly what the espionage report is for.
 */
class EspionageAction implements BotAction
{
    /**
     * How many systems either side of home a bot will scout.
     */
    private const SCOUT_RADIUS = 12;

    public function __construct(private readonly BotFleetService $fleetService)
    {
    }

    /**
     * @inheritDoc
     */
    public function propose(BotContext $context): array
    {
        $player = $context->player;

        // A bot with no interest in ever attacking has no reason to scout.
        if ($context->profile->aggression < 0.15) {
            return [];
        }

        if (!$this->fleetService->hasFreeSlot($player)) {
            return [];
        }

        $candidates = [];

        foreach ($context->planets() as $planet) {
            if ($planet->getObjectAmount('espionage_probe') < 1) {
                continue;
            }

            $target = $this->pickTarget($context, $planet);
            if ($target === null) {
                continue;
            }

            $probes = min(
                $planet->getObjectAmount('espionage_probe'),
                // More probes means a more detailed report. A skilled bot sends enough to see
                // defences; a careless one sends one and learns almost nothing.
                max(1, (int) round(1 + (6 * $context->profile->skill))),
            );

            try {
                $fleet = $this->fleetService->buildFleet($planet, ['espionage_probe' => $probes]);
            } catch (Exception) {
                continue;
            }

            if (!$this->fleetService->canSend($player, $planet, $target, PlanetType::Planet, BotFleetService::MISSION_ESPIONAGE, $fleet)) {
                continue;
            }

            // Scouting is worth more the more aggressive the bot, and much more when it has no
            // usable intel at all: a raider with an empty map cannot pick a target.
            $score = 0.5 + (1.4 * $context->profile->aggression);
            if ($this->freshIntelCount($player->getId()) < 3) {
                $score += 1.2;
            }

            $candidates[] = new ActionCandidate(
                action: 'espionage',
                category: 'raid',
                score: $score,
                reason: sprintf('scout %s with %d probes', $target->asString(), $probes),
                payload: [
                    'planet_id' => $planet->getPlanetId(),
                    'target' => $target->asString(),
                    'probes' => $probes,
                ],
                execute: function () use ($player, $planet, $target, $fleet) {
                    $this->fleetService->send(
                        $player,
                        $planet,
                        $target,
                        PlanetType::Planet,
                        BotFleetService::MISSION_ESPIONAGE,
                        $fleet,
                    );

                    // Record that the bot now believes something is there. The detail arrives
                    // when the report does; this is the "I have looked at this slot" marker that
                    // stops it scouting the same coordinate every tick.
                    $this->rememberScoutedCoordinate($player->getId(), $target);
                },
            );

            break;
        }

        return $candidates;
    }

    /**
     * Pick a coordinate worth scouting.
     *
     * Prefers slots the bot has never looked at, then ones whose intel has gone stale. Anything
     * belonging to the bot itself, or to an account it cannot attack, is skipped.
     */
    private function pickTarget(BotContext $context, PlanetService $planet): Coordinate|null
    {
        $home = $planet->getPlanetCoordinates();
        $ownUserId = $context->player->getId();

        // The galaxy view equivalent: which slots near home are occupied at all.
        $nearby = Planet::query()
            ->where('galaxy', $home->galaxy)
            ->whereBetween('system', [max(1, $home->system - self::SCOUT_RADIUS), $home->system + self::SCOUT_RADIUS])
            ->where('user_id', '!=', $ownUserId)
            ->whereNotNull('user_id')
            ->where('planet_type', PlanetType::Planet->value)
            ->inRandomOrder()
            ->limit(40)
            ->get(['galaxy', 'system', 'planet', 'user_id']);

        if ($nearby->isEmpty()) {
            return null;
        }

        $known = BotIntel::where('bot_user_id', $ownUserId)
            ->where('observed_at', '>=', Date::now()->subHours(12))
            ->get()
            ->keyBy(fn (BotIntel $intel) => $intel->galaxy . ':' . $intel->system . ':' . $intel->position);

        foreach ($nearby as $row) {
            $key = $row->galaxy . ':' . $row->system . ':' . $row->planet;

            // Already looked at recently: nothing new to learn.
            if ($known->has($key)) {
                continue;
            }

            return new Coordinate($row->galaxy, $row->system, $row->planet);
        }

        return null;
    }

    /**
     * How many usable intel records this bot currently holds.
     */
    private function freshIntelCount(int $botUserId): int
    {
        return BotIntel::where('bot_user_id', $botUserId)
            ->where('observed_at', '>=', Date::now()->subHours(24))
            ->count();
    }

    /**
     * Note that the bot has sent probes at a coordinate.
     *
     * Written at dispatch rather than on arrival so the bot does not scout the same slot on every
     * tick while its probes are still in flight. Confidence is low until the report lands and
     * BotIntelWriter fills in what was actually seen.
     */
    private function rememberScoutedCoordinate(int $botUserId, Coordinate $target): void
    {
        BotIntel::updateOrCreate(
            [
                'bot_user_id' => $botUserId,
                'galaxy' => $target->galaxy,
                'system' => $target->system,
                'position' => $target->position,
                'planet_type' => PlanetType::Planet->value,
            ],
            [
                'source' => 'galaxy_view',
                'confidence' => 0.2,
                'observed_at' => Date::now(),
            ],
        );
    }
}
