<?php

namespace OGame\Bots\Support;

use Exception;
use OGame\Enums\BotPersona;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Services\ObjectService;

/**
 * Works out how developed a bot account should be at spawn time.
 *
 * Bots are created with a backdated registration date so the population looks like it
 * accumulated over months rather than appearing at once. Simulating those months tick by tick
 * is not practical, so the state an account "would have reached" is materialised directly from
 * its age, its persona and its skill.
 *
 * Everything produced here is expanded to include its prerequisites before being written, so a
 * spawned account never holds a building or technology it could not legally have built.
 */
class BotProgression
{
    /**
     * Building levels at zero and full progress, per building.
     *
     * The second element is what a long-lived account of this persona tends towards, not a hard
     * cap: it is multiplied by the persona's focus, so a Turtle reaches far more defence and far
     * fewer mines than a Miner of the same age.
     *
     * @var array<string, array{int, int, string}>
     */
    private const BUILDINGS = [
        // machine name              min  max  focus key
        'metal_mine' => [4, 28, 'economy'],
        'crystal_mine' => [3, 24, 'economy'],
        'deuterium_synthesizer' => [1, 20, 'economy'],
        'solar_plant' => [4, 26, 'economy'],
        'metal_store' => [0, 8, 'economy'],
        'crystal_store' => [0, 7, 'economy'],
        'deuterium_store' => [0, 6, 'economy'],
        'robot_factory' => [2, 10, 'economy'],
        'research_lab' => [1, 12, 'research'],
        'shipyard' => [0, 12, 'fleet'],
        'nano_factory' => [0, 4, 'economy'],
    ];

    /**
     * Research levels at zero and full progress, per technology.
     *
     * @var array<string, array{int, int, string}>
     */
    private const RESEARCH = [
        'energy_technology' => [0, 14, 'research'],
        'laser_technology' => [0, 12, 'research'],
        'ion_technology' => [0, 8, 'research'],
        'hyperspace_technology' => [0, 10, 'research'],
        'plasma_technology' => [0, 7, 'research'],
        'espionage_technology' => [0, 11, 'research'],
        'computer_technology' => [0, 12, 'research'],
        'astrophysics' => [0, 12, 'research'],
        'combustion_drive' => [0, 14, 'fleet'],
        'impulse_drive' => [0, 11, 'fleet'],
        'hyperspace_drive' => [0, 9, 'fleet'],
        'weapon_technology' => [0, 14, 'fleet'],
        'shielding_technology' => [0, 12, 'defence'],
        'armor_technology' => [0, 12, 'defence'],
    ];

    /**
     * Ship counts at full progress, scaled by the fleet focus.
     *
     * @var array<string, int>
     */
    private const SHIPS = [
        'small_cargo' => 120,
        'large_cargo' => 60,
        'light_fighter' => 300,
        'heavy_fighter' => 90,
        'cruiser' => 60,
        'battle_ship' => 30,
        'recycler' => 30,
        'espionage_probe' => 40,
    ];

    /**
     * Defence counts at full progress, scaled by the defence focus.
     *
     * @var array<string, int>
     */
    private const DEFENCE = [
        'rocket_launcher' => 900,
        'light_laser' => 400,
        'heavy_laser' => 120,
        'gauss_cannon' => 40,
        'ion_cannon' => 30,
        'plasma_turret' => 10,
    ];

    /**
     * Build the planet column values for a bot's homeworld.
     *
     * @param BotPersona $persona
     * @param int $ageDays How long ago the account "registered".
     * @param float $skill 0..1.
     * @return array<string, int> Planet column name => value.
     */
    public function planetConfig(BotPersona $persona, int $ageDays, float $skill): array
    {
        $config = $persona->config();
        $focus = $this->focus($config);
        $progress = $this->progress($ageDays, (float) ($config['growth'] ?? 1.0), $skill);

        $values = [];

        foreach (self::BUILDINGS as $machineName => [$min, $max, $focusKey]) {
            $values[$machineName] = $this->scale($min, $max, $progress * $focus[$focusKey]);
        }

        // A shipyard is required before ships exist at all, and a bot with no military focus
        // should not have one sitting at level 0 while owning a fleet.
        $fleetScale = $progress * $focus['fleet'];
        $defenceScale = $progress * $focus['defence'];

        foreach (self::SHIPS as $machineName => $atFull) {
            $amount = (int) round($atFull * $fleetScale);
            if ($amount > 0) {
                $values[$machineName] = $amount;
            }
        }

        foreach (self::DEFENCE as $machineName => $atFull) {
            $amount = (int) round($atFull * $defenceScale);
            if ($amount > 0) {
                $values[$machineName] = $amount;
            }
        }

        // Shield domes are single-unit buildings, not stacks.
        if ($defenceScale > 0.45) {
            $values['small_shield_dome'] = 1;
        }
        if ($defenceScale > 0.8) {
            $values['large_shield_dome'] = 1;
        }

        $values += $this->startingResources($progress, $focus['economy'], $skill);

        return $this->expandBuildingRequirements($values);
    }

    /**
     * Build the technology levels for a bot.
     *
     * @return array<string, int> Tech machine name => level.
     */
    public function techConfig(BotPersona $persona, int $ageDays, float $skill): array
    {
        $config = $persona->config();
        $focus = $this->focus($config);
        $progress = $this->progress($ageDays, (float) ($config['growth'] ?? 1.0), $skill);

        $levels = [];

        foreach (self::RESEARCH as $machineName => [$min, $max, $focusKey]) {
            $level = $this->scale($min, $max, $progress * $focus[$focusKey]);
            if ($level > 0) {
                $levels[$machineName] = $level;
            }
        }

        return $this->expandTechRequirements($levels);
    }

    /**
     * Work out how many planets a bot of this age and persona should own.
     */
    public function planetCount(BotPersona $persona, int $ageDays, float $skill): int
    {
        $config = $persona->config();
        /** @var array<int, int> $range */
        $range = is_array($config['planets'] ?? null) ? $config['planets'] : [1, 1];

        $min = max(1, (int) ($range[0] ?? 1));
        $max = max($min, (int) ($range[1] ?? $min));

        $progress = $this->progress($ageDays, (float) ($config['growth'] ?? 1.0), $skill);

        return $this->scale($min, $max, $progress);
    }

    /**
     * Convert an account age into a 0..1 development fraction.
     *
     * The curve is deliberately front-loaded: a two-week-old account is much further from a
     * six-month-old one than a linear scale would suggest, because early mine levels are cheap
     * and later ones are not.
     */
    private function progress(int $ageDays, float $growth, float $skill): float
    {
        $maxAge = max(1, (int) config('bots.account_age_days.max', 180));
        $fraction = min(1.0, max(0.0, $ageDays / $maxAge));

        // Square root gives fast early growth that flattens out, which is how a real account's
        // point curve behaves once buildings start costing exponentially more.
        $curved = $fraction ** 0.5;

        // Skill matters, but not as much as time invested.
        return min(1.0, $curved * $growth * (0.65 + 0.35 * $skill));
    }

    /**
     * Read a persona's focus weights, defaulting anything missing to a neutral value.
     *
     * @param array<string, mixed> $config
     * @return array{economy: float, research: float, fleet: float, defence: float}
     */
    private function focus(array $config): array
    {
        /** @var array<string, mixed> $focus */
        $focus = is_array($config['focus'] ?? null) ? $config['focus'] : [];

        return [
            'economy' => (float) ($focus['economy'] ?? 0.5),
            'research' => (float) ($focus['research'] ?? 0.5),
            'fleet' => (float) ($focus['fleet'] ?? 0.5),
            'defence' => (float) ($focus['defence'] ?? 0.5),
        ];
    }

    /**
     * Interpolate between a minimum and maximum by a 0..1 factor.
     */
    private function scale(int $min, int $max, float $factor): int
    {
        $factor = min(1.0, max(0.0, $factor));

        return (int) round($min + ($max - $min) * $factor);
    }

    /**
     * Work out the resources sitting on the planet at spawn time.
     *
     * Low-skill accounts are deliberately left holding far more than they should: an unspent
     * pile of metal is exactly what a player who logs in twice a day and forgets to queue
     * anything looks like, and it makes them worth raiding.
     *
     * @return array<string, int>
     */
    private function startingResources(float $progress, float $economyFocus, float $skill): array
    {
        $base = 12000 + 900000 * $progress * max(0.4, $economyFocus);

        // A careful player keeps stocks low because they are always spending. A careless one
        // sits on them.
        $hoarding = 0.15 + (1.0 - $skill) * 1.35;

        $metal = (int) round($base * $hoarding * (random_int(60, 140) / 100));
        $crystal = (int) round($metal * (random_int(35, 70) / 100));
        $deuterium = (int) round($metal * (random_int(10, 35) / 100));

        return [
            'metal' => $metal,
            'crystal' => $crystal,
            'deuterium' => $deuterium,
        ];
    }

    /**
     * Expand a planet config so every building it names also has its prerequisites.
     *
     * Without this a bot could be spawned owning, say, a shipyard with no robot factory, which
     * is a state the game itself can never produce.
     *
     * @param array<string, int> $config
     * @return array<string, int>
     */
    private function expandBuildingRequirements(array $config): array
    {
        $expanded = $config;

        foreach ($config as $machineName => $value) {
            if (in_array($machineName, ['metal', 'crystal', 'deuterium'], true)) {
                continue;
            }

            foreach ($this->requirementsOfType($machineName, [GameObjectType::Building, GameObjectType::Station]) as $requirement => $level) {
                if (!isset($expanded[$requirement]) || $expanded[$requirement] < $level) {
                    $expanded[$requirement] = $level;
                }
            }
        }

        return $expanded;
    }

    /**
     * Expand a tech config so every technology it names also has its research prerequisites.
     *
     * @param array<string, int> $levels
     * @return array<string, int>
     */
    private function expandTechRequirements(array $levels): array
    {
        $expanded = $levels;

        foreach ($levels as $machineName => $level) {
            foreach ($this->requirementsOfType($machineName, [GameObjectType::Research]) as $requirement => $requiredLevel) {
                if (!isset($expanded[$requirement]) || $expanded[$requirement] < $requiredLevel) {
                    $expanded[$requirement] = $requiredLevel;
                }
            }
        }

        return $expanded;
    }

    /**
     * Get the recursive requirements of an object, filtered to the given object types.
     *
     * @param array<int, GameObjectType> $types
     * @return array<string, int>
     */
    private function requirementsOfType(string $machineName, array $types): array
    {
        $matched = [];

        try {
            $requirements = ObjectService::getRecursiveRequirements($machineName);
        } catch (Exception) {
            // Not every planet column is a game object (resources, for instance).
            return [];
        }

        foreach ($requirements as $requirement => $level) {
            try {
                $object = ObjectService::getObjectByMachineName($requirement);
            } catch (Exception) {
                continue;
            }

            if (in_array($object->type, $types, true)) {
                $matched[$requirement] = $level;
            }
        }

        return $matched;
    }

    /**
     * Get the building levels required to support the given technology levels.
     *
     * Research needs a research lab, and some technologies need more than the persona's research
     * focus alone would have built, so the homeworld is topped up to match.
     *
     * @param array<string, int> $techLevels
     * @return array<string, int>
     */
    public function buildingRequirementsForTech(array $techLevels): array
    {
        return $this->requirementsFor($techLevels, [GameObjectType::Building, GameObjectType::Station]);
    }

    /**
     * Get the research levels required to support the given planet contents.
     *
     * The dependency runs both ways: a nano factory needs computer technology 10, ships need
     * drives, and defence needs weapons research. Without this a spawned bot could own a
     * building it had no way to unlock.
     *
     * @param array<string, int> $planetConfig
     * @return array<string, int>
     */
    public function researchRequirementsForBuildings(array $planetConfig): array
    {
        $relevant = $planetConfig;
        unset($relevant['metal'], $relevant['crystal'], $relevant['deuterium']);

        return $this->requirementsFor($relevant, [GameObjectType::Research]);
    }

    /**
     * Collect the highest requirement of the given types across a set of objects.
     *
     * @param array<string, int> $objects
     * @param array<int, GameObjectType> $types
     * @return array<string, int>
     */
    private function requirementsFor(array $objects, array $types): array
    {
        $requirements = [];

        foreach (array_keys($objects) as $machineName) {
            foreach ($this->requirementsOfType((string) $machineName, $types) as $requirement => $requiredLevel) {
                if (!isset($requirements[$requirement]) || $requirements[$requirement] < $requiredLevel) {
                    $requirements[$requirement] = $requiredLevel;
                }
            }
        }

        return $requirements;
    }

    /**
     * Merge two level maps, keeping the higher value for any key present in both.
     *
     * Plain array_merge is wrong here: it would let a persona's modest research lab level
     * silently overwrite the higher level that a technology requires.
     *
     * @param array<string, int> $base
     * @param array<string, int> $additional
     * @return array<string, int>
     */
    public function mergeHighest(array $base, array $additional): array
    {
        foreach ($additional as $key => $value) {
            if (!isset($base[$key]) || $base[$key] < $value) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Buildings that may be shrunk to fit a planet's fields.
     *
     * Deliberately limited to buildings that nothing else in a spawned configuration depends on.
     * Scaling a robot factory or a research lab down would break the prerequisites of the
     * shipyard, technologies and units that were expanded from it, producing exactly the illegal
     * state this method exists to prevent. These seven are also the bulk of the levels, so
     * restricting the trim to them costs nothing.
     *
     * @var array<int, string>
     */
    private const SCALABLE = [
        'metal_mine',
        'crystal_mine',
        'deuterium_synthesizer',
        'solar_plant',
        'metal_store',
        'crystal_store',
        'deuterium_store',
    ];

    /**
     * Shrink a planet configuration until its buildings fit within the available fields.
     *
     * A planet whose building levels exceed its field count is a state the game can never
     * produce, and its building queue would refuse to add anything at all. Resources and units
     * are left untouched, since they do not consume fields.
     *
     * @param array<string, int> $planetConfig
     * @param int $maxFields
     * @param float $headroom Fraction of the fields to leave free so the bot can still build.
     * @return array<string, int>
     */
    public function fitToFields(array $planetConfig, int $maxFields, float $headroom = 0.15): array
    {
        $budget = max(1, (int) floor($maxFields * (1 - $headroom)));

        $used = $this->fieldsUsed($planetConfig);
        if ($used <= $budget) {
            return $planetConfig;
        }

        // Everything that cannot be shrunk has first claim on the fields.
        $fixed = 0;
        $scalable = [];
        foreach ($planetConfig as $machineName => $level) {
            if ($level < 1 || !$this->consumesField((string) $machineName)) {
                continue;
            }

            if (in_array($machineName, self::SCALABLE, true)) {
                $scalable[$machineName] = $level;
            } else {
                $fixed += $level;
            }
        }

        $scalableBudget = $budget - $fixed;
        $scalableUsed = array_sum($scalable);

        // Nothing left to give: the prerequisite buildings alone fill the planet. This should not
        // happen with the configured personas, but returning early keeps the result legal rather
        // than scaling required buildings out from under their dependants.
        if ($scalableBudget < 1 || $scalableUsed < 1) {
            return $planetConfig;
        }

        $factor = min(1.0, $scalableBudget / $scalableUsed);
        foreach ($scalable as $machineName => $level) {
            $planetConfig[$machineName] = max(1, (int) floor($level * $factor));
        }

        return $planetConfig;
    }

    /**
     * Count how many planet fields a configuration would occupy.
     *
     * @param array<string, int> $planetConfig
     */
    public function fieldsUsed(array $planetConfig): int
    {
        $used = 0;

        foreach ($planetConfig as $machineName => $level) {
            if ($level > 0 && $this->consumesField((string) $machineName)) {
                $used += $level;
            }
        }

        return $used;
    }

    /**
     * Whether a machine name refers to a building or station that occupies a planet field.
     */
    private function consumesField(string $machineName): bool
    {
        if (in_array($machineName, ['metal', 'crystal', 'deuterium'], true)) {
            return false;
        }

        try {
            $object = ObjectService::getObjectByMachineName($machineName);
        } catch (Exception) {
            return false;
        }

        return in_array($object->type, [GameObjectType::Building, GameObjectType::Station], true)
            && $object->consumesPlanetField;
    }
}
