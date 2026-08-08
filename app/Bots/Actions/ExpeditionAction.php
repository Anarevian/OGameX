<?php

namespace OGame\Bots\Actions;

use Exception;
use OGame\Bots\Brain\ActionCandidate;
use OGame\Bots\Brain\BotContext;
use OGame\Bots\Support\BotFleetService;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Services\PlanetService;

/**
 * Sends expeditions to position 16 of a system.
 *
 * Expeditions are the safest way for a bot to look busy: they are peaceful, they cannot hurt a
 * human player, and they put fleets on the map where the galaxy view and the fleet-event list
 * will show them. For a Discoverer they are also the persona's whole point.
 *
 * The fleet sent is deliberately modest. A bot that throws its entire navy into an expedition and
 * loses it to the loss-of-fleet outcome is not being brave, it is being badly played, so risk
 * tolerance decides what fraction goes.
 */
class ExpeditionAction implements BotAction
{
    /**
     * Expeditions are always sent to the sixteenth slot of a system.
     */
    private const EXPEDITION_POSITION = 16;

    /**
     * Ships worth taking on an expedition, in the order they are drawn on.
     *
     * Cargo capacity is what makes an expedition pay, so cargos come first; a few combat ships
     * come along because some expedition outcomes are fights.
     *
     * @var array<int, string>
     */
    private const FLEET_PREFERENCE = [
        'large_cargo',
        'small_cargo',
        'cruiser',
        'light_fighter',
        'heavy_fighter',
    ];

    public function __construct(private readonly BotFleetService $fleetService)
    {
    }

    /**
     * @inheritDoc
     */
    public function propose(BotContext $context): array
    {
        $player = $context->player;

        if (!$this->fleetService->hasFreeExpeditionSlot($player) || !$this->fleetService->hasFreeSlot($player)) {
            return [];
        }

        // Expeditions need astrophysics, which is also what gates the persona that cares.
        if ($player->getResearchLevel('astrophysics') < 1) {
            return [];
        }

        $candidates = [];

        foreach ($context->planets() as $planet) {
            if ($planet->isMoon()) {
                continue;
            }

            $fleet = $this->assembleFleet($context, $planet);
            if ($fleet === null) {
                continue;
            }

            $target = new Coordinate(
                $planet->getPlanetCoordinates()->galaxy,
                $planet->getPlanetCoordinates()->system,
                self::EXPEDITION_POSITION,
            );

            if (!$this->fleetService->canSend($player, $planet, $target, PlanetType::Planet, BotFleetService::MISSION_EXPEDITION, $fleet)) {
                continue;
            }

            // Discoverers run expeditions constantly; everyone else does it opportunistically.
            $score = 0.6 + (1.6 * $context->focus('research')) + (0.5 * $context->profile->risk_tolerance);

            // Holding time is how long the expedition explores for. One to three hours is the
            // usual choice: long enough to be worth the trip, short enough to reuse the slot.
            $holdingHours = random_int(1, 3);

            $candidates[] = new ActionCandidate(
                action: 'expedition',
                category: 'expansion',
                score: $score,
                reason: sprintf('expedition from %s for %dh', $planet->getPlanetCoordinates()->asString(), $holdingHours),
                payload: [
                    'planet_id' => $planet->getPlanetId(),
                    'target' => $target->asString(),
                    'ships' => $fleet->toArray(),
                    'holding_hours' => $holdingHours,
                ],
                execute: fn () => $this->fleetService->send(
                    $player,
                    $planet,
                    $target,
                    PlanetType::Planet,
                    BotFleetService::MISSION_EXPEDITION,
                    $fleet,
                    null,
                    10,
                    $holdingHours,
                ),
            );

            // One expedition proposal per tick is plenty; the slot limit would reject the rest.
            break;
        }

        return $candidates;
    }

    /**
     * Pick the ships to send, or null when the planet cannot field a worthwhile expedition.
     */
    private function assembleFleet(BotContext $context, PlanetService $planet): UnitCollection|null
    {
        // How much of the available fleet the bot is willing to risk.
        $share = 0.1 + (0.35 * $context->profile->risk_tolerance);

        $wanted = [];
        foreach (self::FLEET_PREFERENCE as $machineName) {
            $available = $planet->getObjectAmount($machineName);
            if ($available < 1) {
                continue;
            }

            $take = (int) floor($available * $share);
            if ($take > 0) {
                $wanted[$machineName] = $take;
            }
        }

        if ($wanted === []) {
            return null;
        }

        try {
            $fleet = $this->fleetService->buildFleet($planet, $wanted);
        } catch (Exception) {
            return null;
        }

        return $fleet->getAmount() > 0 ? $fleet : null;
    }
}
