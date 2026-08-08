<?php

namespace OGame\Bots\Actions;

use Exception;
use OGame\Bots\Brain\ActionCandidate;
use OGame\Bots\Brain\BotContext;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\UnitQueueService;

/**
 * Proposes ships and defence to build.
 *
 * Unlike buildings and research, units are bought in batches, so the interesting decision is
 * not only what to build but how many. Bots spend a persona-dependent slice of what is on the
 * planet rather than everything, because a player who empties their account into light fighters
 * and leaves nothing for the next mine upgrade is playing badly — which is exactly what a
 * low-skill bot should occasionally do, and a high-skill one should not.
 */
class BuildUnitsAction implements BotAction
{
    /**
     * Ships a bot will consider building, with how appealing each is per unit of fleet focus.
     *
     * @var array<string, float>
     */
    private const SHIPS = [
        'small_cargo' => 0.9,
        'large_cargo' => 0.8,
        'light_fighter' => 0.7,
        'heavy_fighter' => 0.6,
        'cruiser' => 0.7,
        'battle_ship' => 0.6,
        'espionage_probe' => 0.5,
        'recycler' => 0.4,
        // Not a fleet asset, but nothing else would ever build one, and without it a bot can
        // never colonise. Gated below so it is only proposed when expansion is actually possible.
        'colony_ship' => 0.7,
    ];

    /**
     * Defence a bot will consider building.
     *
     * @var array<string, float>
     */
    private const DEFENCE = [
        'rocket_launcher' => 0.9,
        'light_laser' => 0.8,
        'heavy_laser' => 0.6,
        'gauss_cannon' => 0.5,
        'ion_cannon' => 0.4,
        'plasma_turret' => 0.4,
    ];

    /**
     * Relative value of each resource, matching the other actions so scores are comparable.
     */
    private const VALUE = ['metal' => 1.0, 'crystal' => 2.0, 'deuterium' => 3.0];

    public function __construct(private readonly UnitQueueService $unitQueueService)
    {
    }

    /**
     * @inheritDoc
     */
    public function propose(BotContext $context): array
    {
        $candidates = [];

        foreach ($context->planets() as $planet) {
            // A shipyard is needed for anything here, and only one batch builds at a time.
            if ($planet->getObjectLevel('shipyard') < 1) {
                continue;
            }

            try {
                if ($this->unitQueueService->isBuildingShipsOrDefense($planet->getPlanetId())) {
                    continue;
                }
            } catch (Exception) {
                continue;
            }

            foreach (self::SHIPS as $machineName => $appeal) {
                $candidate = $this->scoreUnit($context, $planet, $machineName, $appeal, 'fleet');
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }

            foreach (self::DEFENCE as $machineName => $appeal) {
                $candidate = $this->scoreUnit($context, $planet, $machineName, $appeal, 'defence');
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /**
     * Score one unit type on this planet and decide how many to build.
     */
    private function scoreUnit(BotContext $context, PlanetService $planet, string $machineName, float $appeal, string $category): ActionCandidate|null
    {
        $focus = $context->focus($category);
        if ($focus < 0.1) {
            return null;
        }

        if ($machineName === 'colony_ship' && !$this->wantsColonyShip($context, $planet)) {
            return null;
        }

        try {
            if (!ObjectService::objectValidPlanetType($machineName, $planet)) {
                return null;
            }

            if (!ObjectService::objectRequirementsMet($machineName, $planet)) {
                return null;
            }

            $unitPrice = ObjectService::getObjectPrice($machineName, $planet);
        } catch (Exception) {
            return null;
        }

        $unitValue = $this->valueOf($unitPrice);
        if ($unitValue <= 0) {
            return null;
        }

        $amount = $machineName === 'colony_ship'
            ? 1
            : $this->batchSize($context, $planet, $unitPrice);

        if ($amount < 1) {
            return null;
        }

        // One colony ship at a time still has to be affordable.
        if ($machineName === 'colony_ship' && !$planet->hasResources($unitPrice)) {
            return null;
        }

        // Defence beyond a point is wasted: a planet that already has a wall of rocket
        // launchers gains far less from another hundred than from its first hundred.
        $existing = $planet->getObjectAmount($machineName);
        $saturation = 1 / (1 + ($existing / 500));

        $score = $appeal * $focus * $saturation;

        return new ActionCandidate(
            action: $category === 'fleet' ? 'build_ships' : 'build_defence',
            category: $category,
            score: $score,
            reason: sprintf('%dx %s', $amount, $machineName),
            payload: ['planet_id' => $planet->getPlanetId(), 'unit' => $machineName, 'amount' => $amount],
            execute: function () use ($planet, $machineName, $amount) {
                $object = ObjectService::getObjectByMachineName($machineName);
                $this->unitQueueService->add($planet, $object->id, $amount);
            },
        );
    }

    /**
     * Whether a colony ship is worth building right now.
     *
     * Only when the bot is below its astrophysics-derived planet limit and is not already sitting
     * on one it has not used. A stockpile of colony ships is a wasted investment, and a bot that
     * keeps building them while at its limit looks broken.
     */
    private function wantsColonyShip(BotContext $context, PlanetService $planet): bool
    {
        $player = $context->player;

        if (count($player->planets->all()) >= $player->getMaxPlanetAmount()) {
            return false;
        }

        foreach ($player->planets->all() as $owned) {
            if ($owned->getObjectAmount('colony_ship') > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Decide how many units to build in one go.
     *
     * A bot spends a slice of what is currently on the planet, never all of it, and the slice
     * grows with how militarily minded the persona is. Skill matters too: a careless bot will
     * happily spend most of its stock on units and leave nothing for its economy.
     */
    private function batchSize(BotContext $context, PlanetService $planet, Resources $unitPrice): int
    {
        $spendFraction = 0.15 + (0.35 * max($context->focus('fleet'), $context->focus('defence')));

        // Careless players over-commit. Careful ones hold back for the next mine.
        $spendFraction *= 1.4 - (0.6 * $context->profile->skill);
        $spendFraction = min(0.8, $spendFraction);

        $available = [
            'metal' => $planet->metal()->get(),
            'crystal' => $planet->crystal()->get(),
            'deuterium' => $planet->deuterium()->get(),
        ];
        $unitCosts = [
            'metal' => $unitPrice->metal->get(),
            'crystal' => $unitPrice->crystal->get(),
            'deuterium' => $unitPrice->deuterium->get(),
        ];

        $affordable = null;

        foreach ($unitCosts as $resource => $unitCost) {
            if ($unitCost <= 0) {
                continue;
            }

            $budget = $available[$resource] * $spendFraction;
            $forThisResource = (int) floor($budget / $unitCost);
            $affordable = $affordable === null ? $forThisResource : min($affordable, $forThisResource);
        }

        if ($affordable === null) {
            return 0;
        }

        // Cap the batch so a bot cannot queue a build that outlasts its own account activity.
        return max(0, min($affordable, 2000));
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
