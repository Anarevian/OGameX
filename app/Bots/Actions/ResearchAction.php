<?php

namespace OGame\Bots\Actions;

use Exception;
use OGame\Bots\Brain\ActionCandidate;
use OGame\Bots\Brain\BotContext;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\ResearchQueueService;

/**
 * Proposes technologies to research.
 *
 * Research is empire-wide, so unlike buildings there is only one queue and it runs from
 * whichever planet has the lab. Scoring is by persona interest divided by cost, which keeps
 * cheap early technologies attractive and makes expensive ones wait until the economy can
 * carry them — the same shape a human's research order takes.
 */
class ResearchAction implements BotAction
{
    /**
     * How much each persona focus cares about each technology.
     *
     * A value of 1.0 means "core to this playstyle". Anything absent gets a low default, so a
     * bot will still occasionally pick up an off-focus technology, which is what stops every
     * Miner in the universe having an identical research list.
     *
     * @var array<string, array<string, float>>
     */
    private const INTEREST = [
        'economy' => [
            'energy_technology' => 1.0,
            'plasma_technology' => 0.9,
            'computer_technology' => 0.4,
        ],
        'research' => [
            'energy_technology' => 0.8,
            'laser_technology' => 0.7,
            'ion_technology' => 0.6,
            'hyperspace_technology' => 0.7,
            'plasma_technology' => 0.7,
            'espionage_technology' => 0.6,
            'computer_technology' => 0.8,
            'astrophysics' => 1.0,
            'intergalactic_research_network' => 0.7,
            'graviton_technology' => 0.3,
        ],
        'fleet' => [
            'combustion_drive' => 1.0,
            'impulse_drive' => 0.9,
            'hyperspace_drive' => 0.8,
            'weapon_technology' => 0.9,
            'computer_technology' => 0.8,
            'espionage_technology' => 0.7,
        ],
        'defence' => [
            'shielding_technology' => 1.0,
            'armor_technology' => 1.0,
            'laser_technology' => 0.6,
            'ion_technology' => 0.6,
            'plasma_technology' => 0.5,
        ],
    ];

    /**
     * Relative value of each resource, matching BuildBuildingAction so scores are comparable.
     */
    private const VALUE = ['metal' => 1.0, 'crystal' => 2.0, 'deuterium' => 3.0];

    public function __construct(private readonly ResearchQueueService $researchQueueService)
    {
    }

    /**
     * @inheritDoc
     */
    public function propose(BotContext $context): array
    {
        $player = $context->player;

        // Research is empire-wide: one thing at a time, from the planet with the lab.
        if ($player->isResearching()) {
            return [];
        }

        $planet = $this->bestResearchPlanet($context);
        if ($planet === null) {
            return [];
        }

        try {
            if ($this->researchQueueService->retrieveQueue($planet)->isQueueFull()) {
                return [];
            }
        } catch (Exception) {
            return [];
        }

        $candidates = [];

        foreach (ObjectService::getResearchObjects() as $research) {
            $machineName = $research->machine_name;

            try {
                if (!ObjectService::objectRequirementsMet($machineName, $planet)) {
                    continue;
                }

                $price = ObjectService::getObjectPrice($machineName, $planet);
                if (!$planet->hasResources($price)) {
                    continue;
                }
            } catch (Exception) {
                continue;
            }

            $interest = $this->interestIn($context, $machineName);
            if ($interest <= 0) {
                continue;
            }

            $cost = $this->valueOf($price);
            if ($cost <= 0) {
                continue;
            }

            $level = $player->getResearchLevel($machineName);

            // Interest per unit of cost, normalised so a cheap early technology and an
            // expensive late one land on a comparable scale to the building scores.
            $score = $interest * (20000 / $cost);

            // Each level of the same technology is a little less pressing than the last.
            $score /= (1 + $level * 0.15);

            $candidates[] = new ActionCandidate(
                action: 'research',
                category: 'research',
                score: $score,
                reason: sprintf('%s %d, interest %.2f', $machineName, $level + 1, $interest),
                payload: ['planet_id' => $planet->getPlanetId(), 'research' => $machineName, 'level' => $level + 1],
                execute: fn () => $this->researchQueueService->add($context->player, $planet, $research->id),
            );
        }

        return $candidates;
    }

    /**
     * Find the planet a bot would actually start research from: the one with the best lab.
     */
    private function bestResearchPlanet(BotContext $context): PlanetService|null
    {
        $best = null;
        $bestLevel = 0;

        foreach ($context->planets() as $planet) {
            $level = $planet->getObjectLevel('research_lab');
            if ($level > $bestLevel) {
                $bestLevel = $level;
                $best = $planet;
            }
        }

        return $bestLevel > 0 ? $best : null;
    }

    /**
     * How interested this bot is in a technology, blended across its persona's focus weights.
     */
    private function interestIn(BotContext $context, string $machineName): float
    {
        $interest = 0.0;

        foreach (self::INTEREST as $focusKey => $technologies) {
            $weight = $context->focus($focusKey);
            $interest += $weight * ($technologies[$machineName] ?? 0.15);
        }

        return $interest;
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
