<?php

namespace OGame\Bots\Actions;

use Exception;
use OGame\Bots\Brain\ActionCandidate;
use OGame\Bots\Brain\BotContext;
use OGame\Bots\Support\BotFleetService;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Resources;
use OGame\Services\PlanetService;

/**
 * Ships resources between the bot's own planets.
 *
 * This is the only cross-planet coordination a bot has. Without it each planet develops in
 * isolation and a rich colony sits on a full store while the homeworld cannot afford its next
 * mine — which is both bad play and a visible tell, because real players move resources around
 * constantly.
 *
 * The rule is simple and matches what a player does: take from the planet closest to overflowing
 * and give to the one whose next upgrade it would actually fund.
 */
class TransportAction implements BotAction
{
    /**
     * Cargo ships, largest first.
     *
     * @var array<int, string>
     */
    private const CARGO = ['large_cargo', 'small_cargo'];

    /**
     * Only bother when the source planet is at least this full.
     *
     * Below this the trip costs more deuterium than it is worth, and a player would not make it.
     */
    private const OVERFLOW_THRESHOLD = 0.75;

    public function __construct(private readonly BotFleetService $fleetService)
    {
    }

    /**
     * @inheritDoc
     */
    public function propose(BotContext $context): array
    {
        $player = $context->player;

        $planets = $context->planets();
        if (count($planets) < 2 || !$this->fleetService->hasFreeSlot($player)) {
            return [];
        }

        $source = $this->pickSource($planets);
        if ($source === null) {
            return [];
        }

        [$from, $fillRatio] = $source;

        $to = $this->pickDestination($from, $planets);
        if ($to === null) {
            return [];
        }

        $cargo = $this->assembleCargo($from);
        if ($cargo === null) {
            return [];
        }

        [$fleet, $capacity] = $cargo;

        $resources = $this->loadOverflow($from, $capacity);
        if ($resources->sum() < 1) {
            return [];
        }

        $target = $to->getPlanetCoordinates();

        if (!$this->fleetService->canSend($player, $from, $target, PlanetType::Planet, BotFleetService::MISSION_TRANSPORT, $fleet)) {
            return [];
        }

        // The fuller the source, the more urgent: production above the storage cap is thrown away.
        $score = 0.8 + (2.0 * $fillRatio);

        return [
            new ActionCandidate(
                action: 'transport',
                category: 'economy',
                score: $score,
                reason: sprintf(
                    'ship %dk from %s to %s, source %d%% full',
                    (int) round($resources->sum() / 1000),
                    $from->getPlanetCoordinates()->asString(),
                    $target->asString(),
                    (int) round($fillRatio * 100),
                ),
                payload: [
                    'planet_id' => $from->getPlanetId(),
                    'target' => $target->asString(),
                    'ships' => $fleet->toArray(),
                ],
                execute: fn () => $this->fleetService->send(
                    $player,
                    $from,
                    $target,
                    PlanetType::Planet,
                    BotFleetService::MISSION_TRANSPORT,
                    $fleet,
                    $resources,
                ),
            ),
        ];
    }

    /**
     * Find the planet closest to overflowing.
     *
     * @param array<int, PlanetService> $planets
     * @return array{PlanetService, float}|null
     */
    private function pickSource(array $planets): array|null
    {
        $best = null;
        $bestRatio = self::OVERFLOW_THRESHOLD;

        foreach ($planets as $planet) {
            $ratio = $this->fillRatio($planet);

            if ($ratio > $bestRatio) {
                $best = $planet;
                $bestRatio = $ratio;
            }
        }

        return $best === null ? null : [$best, $bestRatio];
    }

    /**
     * Find the emptiest other planet, which is the one that most needs the resources.
     *
     * @param array<int, PlanetService> $planets
     */
    private function pickDestination(PlanetService $from, array $planets): PlanetService|null
    {
        $best = null;
        $bestRatio = 1.1;

        foreach ($planets as $planet) {
            if ($planet->getPlanetId() === $from->getPlanetId()) {
                continue;
            }

            $ratio = $this->fillRatio($planet);
            if ($ratio < $bestRatio) {
                $best = $planet;
                $bestRatio = $ratio;
            }
        }

        return $best;
    }

    /**
     * How full a planet's stores are, taking the fullest of the three resources.
     */
    private function fillRatio(PlanetService $planet): float
    {
        $ratios = [];

        $metalCap = $planet->metalStorage()->get();
        if ($metalCap > 0) {
            $ratios[] = $planet->metal()->get() / $metalCap;
        }

        $crystalCap = $planet->crystalStorage()->get();
        if ($crystalCap > 0) {
            $ratios[] = $planet->crystal()->get() / $crystalCap;
        }

        $deuteriumCap = $planet->deuteriumStorage()->get();
        if ($deuteriumCap > 0) {
            $ratios[] = $planet->deuterium()->get() / $deuteriumCap;
        }

        return $ratios === [] ? 0.0 : max($ratios);
    }

    /**
     * Pick cargo ships for the run.
     *
     * @return array{UnitCollection, int}|null
     */
    private function assembleCargo(PlanetService $from): array|null
    {
        $owner = $from->getPlayer();
        if ($owner === null) {
            return null;
        }

        $wanted = [];
        foreach (self::CARGO as $machineName) {
            $available = $from->getObjectAmount($machineName);
            if ($available > 0) {
                // Leave some cargo behind: a player does not send their whole freight fleet on
                // an internal run and leave nothing for the next one.
                $wanted[$machineName] = max(1, (int) floor($available * 0.6));
            }
        }

        if ($wanted === []) {
            return null;
        }

        try {
            $fleet = $this->fleetService->buildFleet($from, $wanted);
            $capacity = $fleet->getTotalCargoCapacity($owner);
        } catch (Exception) {
            return null;
        }

        if ($fleet->getAmount() < 1 || $capacity < 1) {
            return null;
        }

        return [$fleet, $capacity];
    }

    /**
     * Load whatever is closest to spilling, up to the fleet's capacity.
     */
    private function loadOverflow(PlanetService $from, int $capacity): Resources
    {
        $available = [
            'metal' => (int) $from->metal()->get(),
            'crystal' => (int) $from->crystal()->get(),
            'deuterium' => (int) $from->deuterium()->get(),
        ];

        // Keep a working balance on the source planet rather than stripping it bare.
        $take = [];
        foreach ($available as $resource => $amount) {
            $take[$resource] = (int) floor($amount * 0.5);
        }

        // Trim to what the ships can actually lift, most valuable first.
        $remaining = $capacity;
        $loadedDeuterium = min($take['deuterium'], $remaining);
        $remaining -= $loadedDeuterium;
        $loadedCrystal = min($take['crystal'], $remaining);
        $remaining -= $loadedCrystal;
        $loadedMetal = min($take['metal'], $remaining);

        return new Resources($loadedMetal, $loadedCrystal, $loadedDeuterium, 0);
    }
}
