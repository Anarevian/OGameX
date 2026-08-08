<?php

namespace OGame\Bots\Actions;

use Exception;
use OGame\Bots\Brain\ActionCandidate;
use OGame\Bots\Brain\BotContext;
use OGame\Bots\Support\BotFleetService;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Services\PlanetService;

/**
 * Sends recyclers to harvest debris fields.
 *
 * Debris is free resources lying in the open, and it is what makes a battle-heavy neighbourhood
 * feel alive: wrecks appear, someone comes and clears them. A bot that ignores debris — including
 * its own, after losing a fight — reads as not paying attention.
 *
 * Debris fields are visible in the galaxy view to anyone, so reading them directly is fair.
 */
class RecycleAction implements BotAction
{
    /**
     * How many systems either side of home to look for debris.
     */
    private const SEARCH_RADIUS = 10;

    /**
     * Below this total, the trip is not worth the deuterium.
     */
    private const MIN_WORTHWHILE = 8000;

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

        foreach ($context->planets() as $planet) {
            $recyclers = $planet->getObjectAmount('recycler');
            if ($recyclers < 1) {
                continue;
            }

            $field = $this->findWorthwhileField($planet);
            if ($field === null) {
                continue;
            }

            [$coordinate, $total] = $field;

            // A recycler holds 20,000. Send enough to clear it, plus a margin, but never more
            // than the planet has.
            $needed = min($recyclers, max(1, (int) ceil($total / 20000) + 1));

            try {
                $fleet = $this->fleetService->buildFleet($planet, ['recycler' => $needed]);
            } catch (Exception) {
                continue;
            }

            if (!$this->fleetService->canSend($player, $planet, $coordinate, PlanetType::DebrisField, BotFleetService::MISSION_RECYCLE, $fleet)) {
                continue;
            }

            // Free resources, scaled by how much is there. Capped like every other scorer.
            $score = min(2.5, $total / 60000);

            return [
                new ActionCandidate(
                    action: 'recycle',
                    category: 'economy',
                    score: $score,
                    reason: sprintf('harvest %dk debris at %s with %d recyclers', (int) round($total / 1000), $coordinate->asString(), $needed),
                    payload: [
                        'planet_id' => $planet->getPlanetId(),
                        'target' => $coordinate->asString(),
                        'recyclers' => $needed,
                    ],
                    execute: fn () => $this->fleetService->send(
                        $player,
                        $planet,
                        $coordinate,
                        PlanetType::DebrisField,
                        BotFleetService::MISSION_RECYCLE,
                        $fleet,
                    ),
                ),
            ];
        }

        return [];
    }

    /**
     * Find the largest nearby debris field worth collecting.
     *
     * @return array{Coordinate, float}|null
     */
    private function findWorthwhileField(PlanetService $planet): array|null
    {
        $home = $planet->getPlanetCoordinates();

        $fields = DebrisField::query()
            ->where('galaxy', $home->galaxy)
            ->whereBetween('system', [max(1, $home->system - self::SEARCH_RADIUS), $home->system + self::SEARCH_RADIUS])
            ->get();

        $best = null;
        $bestTotal = self::MIN_WORTHWHILE;

        foreach ($fields as $field) {
            $total = (float) $field->metal + (float) $field->crystal + (float) $field->deuterium;

            if ($total > $bestTotal) {
                $best = new Coordinate($field->galaxy, $field->system, $field->planet);
                $bestTotal = $total;
            }
        }

        return $best === null ? null : [$best, $bestTotal];
    }
}
