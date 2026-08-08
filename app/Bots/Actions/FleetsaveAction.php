<?php

namespace OGame\Bots\Actions;

use Exception;
use OGame\Bots\Brain\ActionCandidate;
use OGame\Bots\Brain\BotContext;
use OGame\Bots\Support\BotFleetService;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Flies a threatened fleet away so it is not on the planet when an attack lands.
 *
 * A bot sees an incoming hostile fleet exactly the way a player does — it is in the fleet
 * movement list, visible on the planet under threat — so acting on it is fair. What separates a
 * good player from a bad one is whether they are *there* to react, and whether they get it right.
 *
 * Both are modelled here rather than assumed:
 *  - The action only ever runs inside the bot's waking hours, because the tick does. A bot asleep
 *    when the attack lands loses its fleet, which is the single most common way real players lose
 *    theirs.
 *  - Skill decides whether it reacts at all. A careless bot rolls badly and leaves the fleet
 *    sitting there even while online.
 *
 * The save itself is a transport to one of the bot's own planets carrying the planet's resources,
 * which is what most players actually do: it moves the fleet and empties the target of loot in
 * one mission.
 */
class FleetsaveAction implements BotAction
{
    /**
     * Mission types that count as hostile, matching FleetMissionService::currentPlayerUnderAttack().
     *
     * Espionage is excluded: probes are a nuisance, not a reason to move a fleet.
     *
     * @var array<int, int>
     */
    private const HOSTILE_MISSIONS = [1, 2, 9];

    /**
     * Ships worth saving, in the order they are loaded.
     *
     * @var array<int, string>
     */
    private const SAVEABLE = [
        'battle_ship',
        'destroyer',
        'bomber',
        'battlecruiser',
        'cruiser',
        'heavy_fighter',
        'light_fighter',
        'large_cargo',
        'small_cargo',
        'recycler',
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

        if (!$this->fleetService->hasFreeSlot($player)) {
            return [];
        }

        // A bot needs somewhere to send the fleet, which means a second planet.
        $planets = $context->planets();
        if (count($planets) < 2) {
            return [];
        }

        $candidates = [];

        foreach ($planets as $planet) {
            if (!$this->isUnderThreat($planet)) {
                continue;
            }

            // Does this bot notice and act? A careless player is online and still does nothing.
            // Rolled per proposal so the same bot does not deterministically always save.
            if ((random_int(1, 100) / 100) > $this->reactionChance($context)) {
                continue;
            }

            $destination = $this->pickDestination($planet, $planets);
            if ($destination === null) {
                continue;
            }

            $fleet = $this->assembleFleet($planet);
            if ($fleet === null) {
                continue;
            }

            $resources = $this->loadableResources($planet, $fleet, $player);

            $target = $destination->getPlanetCoordinates();

            if (!$this->fleetService->canSend($player, $planet, $target, PlanetType::Planet, BotFleetService::MISSION_TRANSPORT, $fleet)) {
                continue;
            }

            // Saving a fleet outranks anything else the bot could be doing. It is the one
            // decision where being a few minutes late costs more than every build in the queue.
            $candidates[] = new ActionCandidate(
                action: 'fleetsave',
                category: 'defence',
                score: 3.0,
                reason: sprintf(
                    'fleetsave %s to %s under threat',
                    $planet->getPlanetCoordinates()->asString(),
                    $target->asString(),
                ),
                payload: [
                    'planet_id' => $planet->getPlanetId(),
                    'target' => $target->asString(),
                    'ships' => $fleet->toArray(),
                ],
                execute: fn () => $this->fleetService->send(
                    $player,
                    $planet,
                    $target,
                    PlanetType::Planet,
                    BotFleetService::MISSION_TRANSPORT,
                    $fleet,
                    $resources,
                ),
            );

            break;
        }

        return $candidates;
    }

    /**
     * Whether a hostile fleet is currently inbound to this planet.
     */
    private function isUnderThreat(PlanetService $planet): bool
    {
        $owner = $planet->getPlayer();
        if ($owner === null) {
            return false;
        }

        return FleetMission::query()
            ->where('planet_id_to', $planet->getPlanetId())
            ->where('user_id', '!=', $owner->getId())
            ->whereIn('mission_type', self::HOSTILE_MISSIONS)
            ->where('processed', 0)
            ->exists();
    }

    /**
     * How likely this bot is to react to the threat at all.
     *
     * Even a very good player misses one occasionally, and a careless one misses most.
     */
    private function reactionChance(BotContext $context): float
    {
        return 0.15 + (0.8 * $context->profile->skill);
    }

    /**
     * Pick another of the bot's planets to send the fleet to.
     *
     * @param array<int, PlanetService> $planets
     */
    private function pickDestination(PlanetService $from, array $planets): PlanetService|null
    {
        foreach ($planets as $candidate) {
            if ($candidate->getPlanetId() === $from->getPlanetId()) {
                continue;
            }

            // Never send a threatened fleet to another planet that is also under attack.
            if ($this->isUnderThreat($candidate)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Load everything on the planet that can fly.
     */
    private function assembleFleet(PlanetService $planet): UnitCollection|null
    {
        $wanted = [];

        foreach (self::SAVEABLE as $machineName) {
            $available = $planet->getObjectAmount($machineName);
            if ($available > 0) {
                $wanted[$machineName] = $available;
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

    /**
     * Take as much of the planet's stock along as the fleet can carry.
     *
     * Emptying the planet is half the point of a fleetsave: an attacker who arrives to find
     * nothing worth taking usually turns round.
     */
    private function loadableResources(PlanetService $planet, UnitCollection $fleet, PlayerService $player): Resources
    {
        try {
            $capacity = $fleet->getTotalCargoCapacity($player);
        } catch (Exception) {
            return new Resources(0, 0, 0, 0);
        }

        if ($capacity < 1) {
            return new Resources(0, 0, 0, 0);
        }

        $metal = (int) $planet->metal()->get();
        $crystal = (int) $planet->crystal()->get();
        $deuterium = (int) $planet->deuterium()->get();

        // Fill with the most valuable first, since capacity is usually the binding constraint.
        $takeDeuterium = (int) min($deuterium, $capacity);
        $capacity -= $takeDeuterium;
        $takeCrystal = (int) min($crystal, $capacity);
        $capacity -= $takeCrystal;
        $takeMetal = (int) min($metal, $capacity);

        return new Resources($takeMetal, $takeCrystal, $takeDeuterium, 0);
    }
}
