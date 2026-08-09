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
     * Buildings whose first level unlocks a whole branch of the game.
     *
     * @var array<int, string>
     */
    private const GATEWAYS = ['robot_factory', 'research_lab', 'shipyard'];

    /**
     * Roughly how much a unit of crystal and deuterium is worth relative to metal.
     *
     * Used to turn a mixed cost or production into one comparable number. The ratios are the
     * long-standing community rule of thumb rather than anything the game defines.
     */
    private const VALUE = ['metal' => 1.0, 'crystal' => 2.0, 'deuterium' => 3.0];

    /**
     * Ceiling every scorer in this class saturates towards.
     *
     * All actions must produce comparable numbers or the brain stops being a ranking and becomes
     * a fixed priority list: whichever class happens to emit the largest magnitude always wins.
     */
    private const MAX_SCORE = 3.0;

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

        // The cutoff is deliberately generous rather than tight. A tight one starves a developed
        // account: once its cheap colony mines are done, every remaining upgrade is expensive, and
        // a bot that refuses all of them plateaus with millions of unspent metal sitting on the
        // planet. Ranking handles the ordering; this only discards the genuinely absurd.
        if ($paybackHours > 2000) {
            return null;
        }

        // Saturating rather than dividing. A raw 24/payback is unbounded as payback shrinks: a
        // level-1 mine on a fresh colony pays back in about an hour and scored 17, which beat
        // every fleet, research and expedition candidate the bot had, so it did nothing but
        // build. Saturation keeps the same ordering — faster payback still wins — on the same
        // 0..3 scale every other action produces.
        $score = self::MAX_SCORE * (24 / ($paybackHours + 24));

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
            // An energy deficit scales the planet's whole production down, so it belongs at the
            // top of the range — but bounded, like everything else.
            $score = 2.5 + min(1.5, abs($available) / 1000);
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

        $score = $fillRatio >= 0.98 ? self::MAX_SCORE : 1.2 + (1.5 * $fillRatio);

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

        // The first level of a gateway building unlocks a whole branch of the game: no research
        // lab means no research at all, ever, and no shipyard means no ships or defence. A bot
        // that never builds one stays locked out however much metal it piles up — an account
        // built from nothing reached mine level 19 with millions unspent and still had no lab,
        // because a mine upgrade always out-scores an incremental infrastructure one.
        if ($level === 0 && in_array($machineName, self::GATEWAYS, true)) {
            $base = max($base, 2.6);
        }

        $score = $base / (1 + ($level * 0.35));

        return new ActionCandidate(
            action: 'build_building',
            // Its own category, so session fatigue tells it apart from mine upgrades. Sharing
            // "economy" meant fatigue scaled both together and the ordering never changed, so
            // whichever scored higher on the first action won every action after it too.
            category: 'infrastructure',
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

            if ($this->alreadyQueued($planet, $machineName)) {
                return false;
            }

            return $planet->hasResources(ObjectService::getObjectPrice($machineName, $planet));
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Whether this building already has an entry waiting in the planet's queue.
     *
     * Every score here is derived from `getObjectLevel()`, which reports what is **built** and
     * knows nothing about what is queued. The brain re-proposes after each action, so without
     * this check a bot scored the same upgrade at the same stale level over and over: it queued
     * "metal mine 2" five times in one session, each time priced and paid-back as though the mine
     * were still level 1. A mine's payback is what makes it win, and payback that never rises
     * meant mines won every slot until the queue was full.
     *
     * The visible damage was everything the user of this system would notice. Bots built nothing
     * but mines and stores; solar plants never appeared, so the planets sat in permanent energy
     * deficit; and the robot factory, shipyard and research lab were never reached at all, which
     * locked the accounts out of research and shipbuilding permanently.
     *
     * One entry per building per queue also happens to be what a competent player does early on:
     * a mine, a different mine, a solar plant, a lab — not the same mine five times.
     */
    private function alreadyQueued(PlanetService $planet, string $machineName): bool
    {
        try {
            $objectId = ObjectService::getObjectByMachineName($machineName)->id;

            return $this->buildingQueueService->activeBuildingQueueItemCount($planet, $objectId) > 0;
        } catch (Exception) {
            // Unknown object: let the normal requirement checks decide.
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
