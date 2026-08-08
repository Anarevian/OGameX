<?php

namespace OGame\Bots\Actions;

use Exception;
use OGame\Bots\Brain\ActionCandidate;
use OGame\Bots\Brain\BotContext;
use OGame\Models\Resources;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;

/**
 * Proposes building upgrades.
 *
 * This is where most of a bot's economy comes from, so the scoring is the most developed part
 * of the brain. Each building type is scored on what it is actually for:
 *
 *  - Mines are scored on payback time: how many hours of extra production it takes to earn back
 *    the upgrade. That single number naturally produces a sensible build order without anyone
 *    hardcoding one, and it shifts correctly as costs grow and as a planet's ratios change.
 *  - Energy is scored on the deficit it fixes. A planet running at an energy shortfall has its
 *    entire production scaled down, so this outranks almost everything when it bites.
 *  - Storage is scored on overflow risk. Production that overflows a full store is thrown away.
 *  - Infrastructure (robot factory, shipyard, lab, nano) is scored on what it unlocks, at a
 *    steady low-to-middling value, so it gets built between the urgent things.
 */
class BuildBuildingAction implements BotAction
{
    /**
     * Buildings a bot will consider upgrading, grouped by how they are scored.
     *
     * @var array<int, string>
     */
    private const MINES = ['metal_mine', 'crystal_mine', 'deuterium_synthesizer'];

    /**
     * @var array<int, string>
     */
    private const ENERGY = ['solar_plant'];

    /**
     * @var array<int, string>
     */
    private const STORAGE = ['metal_store', 'crystal_store', 'deuterium_store'];

    /**
     * @var array<int, string>
     */
    private const INFRASTRUCTURE = ['robot_factory', 'research_lab', 'shipyard', 'nano_factory'];

    /**
     * Roughly how much a unit of crystal and deuterium is worth relative to metal.
     *
     * Used to turn a mixed cost or production into one comparable number. The ratios are the
     * long-standing community rule of thumb rather than anything the game defines.
     */
    private const VALUE = ['metal' => 1.0, 'crystal' => 2.0, 'deuterium' => 3.0];

    public function __construct(private readonly BuildingQueueService $buildingQueueService)
    {
    }

    /**
     * @inheritDoc
     */
    public function propose(BotContext $context): array
    {
        $candidates = [];

        foreach ($context->planets() as $planet) {
            // One building at a time per planet, as the game enforces.
            try {
                if ($this->buildingQueueService->retrieveQueue($planet)->isQueueFull()) {
                    continue;
                }
            } catch (Exception) {
                continue;
            }

            $fieldsFree = $planet->getPlanetFieldMax() - $planet->getBuildingCount();
            if ($fieldsFree < 1) {
                continue;
            }

            foreach (self::MINES as $machineName) {
                $candidate = $this->scoreMine($context, $planet, $machineName);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }

            foreach (self::ENERGY as $machineName) {
                $candidate = $this->scoreEnergy($context, $planet, $machineName);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }

            foreach (self::STORAGE as $machineName) {
                $candidate = $this->scoreStorage($context, $planet, $machineName);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }

            foreach (self::INFRASTRUCTURE as $machineName) {
                $candidate = $this->scoreInfrastructure($context, $planet, $machineName);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /**
     * Score a mine upgrade on how quickly it pays for itself.
     */
    private function scoreMine(BotContext $context, PlanetService $planet, string $machineName): ActionCandidate|null
    {
        if (!$this->canBuild($planet, $machineName)) {
            return null;
        }

        $level = $planet->getObjectLevel($machineName);
        $cost = $this->valueOf(ObjectService::getObjectPrice($machineName, $planet));

        try {
            $current = $planet->getObjectProduction($machineName, $level, true);
            $next = $planet->getObjectProduction($machineName, $level + 1, true);
        } catch (Exception) {
            return null;
        }

        $gainPerHour = $this->valueOf($next) - $this->valueOf($current);

        if ($gainPerHour <= 0 || $cost <= 0) {
            return null;
        }

        $paybackHours = $cost / $gainPerHour;

        // Score falls off as payback grows. 24h payback scores 1.0, 240h scores 0.1. Anything
        // beyond about three weeks of payback is not worth a decision slot.
        if ($paybackHours > 500) {
            return null;
        }

        $score = 24 / $paybackHours;

        return new ActionCandidate(
            action: 'build_building',
            category: 'economy',
            score: $score,
            reason: sprintf('%s %d, payback %dh', $machineName, $level + 1, (int) round($paybackHours)),
            payload: ['planet_id' => $planet->getPlanetId(), 'building' => $machineName, 'level' => $level + 1],
            execute: fn () => $this->queue($planet, $machineName),
        );
    }

    /**
     * Score an energy building on the deficit it would fix.
     */
    private function scoreEnergy(BotContext $context, PlanetService $planet, string $machineName): ActionCandidate|null
    {
        if (!$this->canBuild($planet, $machineName)) {
            return null;
        }

        $level = $planet->getObjectLevel($machineName);
        $available = $planet->energy()->get();

        // A planet in energy deficit runs every mine at reduced output, so fixing it is worth
        // more than any single mine upgrade. Below zero the urgency scales with the shortfall.
        if ($available < 0) {
            $score = 3.0 + min(5.0, abs($available) / 500);
            $reason = sprintf('%s %d, energy deficit %d', $machineName, $level + 1, (int) $available);
        } elseif ($available < 50) {
            // Nearly out: build ahead of the next mine upgrade rather than after it.
            $score = 1.4;
            $reason = sprintf('%s %d, energy nearly exhausted', $machineName, $level + 1);
        } else {
            return null;
        }

        return new ActionCandidate(
            action: 'build_building',
            category: 'economy',
            score: $score,
            reason: $reason,
            payload: ['planet_id' => $planet->getPlanetId(), 'building' => $machineName, 'level' => $level + 1],
            execute: fn () => $this->queue($planet, $machineName),
        );
    }

    /**
     * Score a storage upgrade on how close the matching resource is to overflowing.
     */
    private function scoreStorage(BotContext $context, PlanetService $planet, string $machineName): ActionCandidate|null
    {
        if (!$this->canBuild($planet, $machineName)) {
            return null;
        }

        [$current, $capacity, $perHour] = match ($machineName) {
            'metal_store' => [$planet->metal()->get(), $planet->metalStorage()->get(), $planet->getMetalProductionPerHour()],
            'crystal_store' => [$planet->crystal()->get(), $planet->crystalStorage()->get(), $planet->getCrystalProductionPerHour()],
            default => [$planet->deuterium()->get(), $planet->deuteriumStorage()->get(), $planet->getDeuteriumProductionPerHour()],
        };

        if ($capacity <= 0 || $perHour <= 0) {
            return null;
        }

        $fillRatio = $current / $capacity;
        $hoursToFull = ($capacity - $current) / $perHour;

        // Only interesting once the store is actually at risk. A bot that logs in twice a day
        // needs roughly twelve hours of headroom; less than that and production is being lost.
        if ($fillRatio < 0.7 && $hoursToFull > 12) {
            return null;
        }

        $score = $fillRatio >= 0.98 ? 4.0 : 1.2 + (2.0 * $fillRatio);

        return new ActionCandidate(
            action: 'build_building',
            category: 'economy',
            score: $score,
            reason: sprintf('%s %d, store %d%% full', $machineName, $planet->getObjectLevel($machineName) + 1, (int) round($fillRatio * 100)),
            payload: ['planet_id' => $planet->getPlanetId(), 'building' => $machineName],
            execute: fn () => $this->queue($planet, $machineName),
        );
    }

    /**
     * Score infrastructure on what it unlocks rather than on direct return.
     */
    private function scoreInfrastructure(BotContext $context, PlanetService $planet, string $machineName): ActionCandidate|null
    {
        if (!$this->canBuild($planet, $machineName)) {
            return null;
        }

        $level = $planet->getObjectLevel($machineName);

        // Each of these is worth building for a different persona, and each gets steadily less
        // interesting as it climbs, so a bot does not sink its whole economy into one of them.
        $base = match ($machineName) {
            'robot_factory' => 0.9,
            'research_lab' => 0.8 * (0.5 + $context->focus('research')),
            'shipyard' => 0.8 * (0.4 + max($context->focus('fleet'), $context->focus('defence'))),
            'nano_factory' => 0.7,
            default => 0.5,
        };

        $score = $base / (1 + ($level * 0.35));

        return new ActionCandidate(
            action: 'build_building',
            category: 'economy',
            score: $score,
            reason: sprintf('%s %d, infrastructure', $machineName, $level + 1),
            payload: ['planet_id' => $planet->getPlanetId(), 'building' => $machineName, 'level' => $level + 1],
            execute: fn () => $this->queue($planet, $machineName),
        );
    }

    /**
     * Whether this building can legally be upgraded on this planet right now, and paid for.
     *
     * Affordability matters as much as legality: BuildingQueueService::start() cancels a queued
     * item outright when its resources cannot be paid, so queueing something unaffordable
     * silently throws away the bot's decision instead of deferring it.
     */
    private function canBuild(PlanetService $planet, string $machineName): bool
    {
        try {
            if (!ObjectService::objectValidPlanetType($machineName, $planet)) {
                return false;
            }

            if (!ObjectService::objectRequirementsMet($machineName, $planet)) {
                return false;
            }

            return $planet->hasResources(ObjectService::getObjectPrice($machineName, $planet));
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Add the upgrade to the planet's building queue.
     *
     * @throws Exception
     */
    private function queue(PlanetService $planet, string $machineName): void
    {
        $object = ObjectService::getObjectByMachineName($machineName);

        $this->buildingQueueService->add($planet, $object->id);
    }

    /**
     * Reduce a resource bundle to a single comparable number.
     */
    private function valueOf(Resources $resources): float
    {
        return ($resources->metal->get() * self::VALUE['metal'])
            + ($resources->crystal->get() * self::VALUE['crystal'])
            + ($resources->deuterium->get() * self::VALUE['deuterium']);
    }
}
