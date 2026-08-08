<?php

namespace OGame\Bots\Actions;

use Exception;
use OGame\Bots\Brain\ActionCandidate;
use OGame\Bots\Brain\BotContext;
use OGame\Bots\Support\BotFleetService;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Services\PlanetService;

/**
 * Sends a colony ship at an empty slot.
 *
 * Expansion is the most visible thing a bot does to the galaxy map: new planets appear in systems
 * a human browses. It is also the thing that makes an Explorer's astrophysics investment pay off.
 *
 * The bot only colonises what the game would let it: an empty slot, within the position range its
 * astrophysics level allows, while it is below its planet limit.
 */
class ColoniseAction implements BotAction
{
    /**
     * How many systems either side of home to look for a free slot.
     */
    private const SEARCH_RADIUS = 15;

    /**
     * How many candidate slots to try before giving up this tick.
     */
    private const MAX_ATTEMPTS = 25;

    public function __construct(private readonly BotFleetService $fleetService)
    {
    }

    /**
     * @inheritDoc
     */
    public function propose(BotContext $context): array
    {
        $player = $context->player;

        if (!$this->fleetService->hasFreeSlot($player)) {
            return [];
        }

        // Already at the astrophysics-derived planet limit.
        if (count($context->planets()) >= $player->getMaxPlanetAmount()) {
            return [];
        }

        foreach ($context->planets() as $planet) {
            if ($planet->getObjectAmount('colony_ship') < 1) {
                continue;
            }

            $target = $this->findFreeSlot($context, $planet);
            if ($target === null) {
                continue;
            }

            try {
                $fleet = $this->fleetService->buildFleet($planet, ['colony_ship' => 1]);
            } catch (Exception) {
                continue;
            }

            if (!$this->fleetService->canSend($player, $planet, $target, PlanetType::Planet, BotFleetService::MISSION_COLONISATION, $fleet)) {
                continue;
            }

            // Expansion is worth more the fewer planets the bot has: the first colony roughly
            // doubles an empire, the seventh barely moves it.
            $owned = max(1, count($context->planets()));
            $score = (1.0 + (1.5 * $context->focus('research'))) / $owned;

            return [
                new ActionCandidate(
                    action: 'colonise',
                    category: 'expansion',
                    score: $score,
                    reason: sprintf('colonise %s, planet %d of %d', $target->asString(), $owned + 1, $player->getMaxPlanetAmount()),
                    payload: [
                        'planet_id' => $planet->getPlanetId(),
                        'target' => $target->asString(),
                    ],
                    execute: fn () => $this->fleetService->send(
                        $player,
                        $planet,
                        $target,
                        PlanetType::Planet,
                        BotFleetService::MISSION_COLONISATION,
                        $fleet,
                    ),
                ),
            ];
        }

        return [];
    }

    /**
     * Look for an empty slot the bot's astrophysics level permits.
     *
     * Which slots are occupied is galaxy-view knowledge, which every player has for free, so
     * reading it directly here is fair — unlike defences or stockpiles, which must come from an
     * espionage report.
     */
    private function findFreeSlot(BotContext $context, PlanetService $from): Coordinate|null
    {
        $home = $from->getPlanetCoordinates();
        $player = $context->player;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $system = $home->system + random_int(-self::SEARCH_RADIUS, self::SEARCH_RADIUS);
            if ($system < 1) {
                continue;
            }

            $position = random_int(1, 15);

            if (!$player->canColonizePosition($position)) {
                continue;
            }

            $occupied = Planet::where('galaxy', $home->galaxy)
                ->where('system', $system)
                ->where('planet', $position)
                ->exists();

            if (!$occupied) {
                return new Coordinate($home->galaxy, $system, $position);
            }
        }

        return null;
    }
}
