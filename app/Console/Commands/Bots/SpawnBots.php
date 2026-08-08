<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OGame\Bots\Support\BotNameGenerator;
use OGame\Bots\Support\BotPersonaRoller;
use OGame\Bots\Support\BotProgression;
use OGame\Bots\Support\BotSynchroniser;
use OGame\Bots\Support\ReadsScalarOptions;
use OGame\Enums\BotLod;
use OGame\Enums\BotPersona;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BotProfile;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Models\UserTech;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use RuntimeException;
use Throwable;

/**
 * Creates NPC accounts that a bot brain can later drive.
 *
 * Accounts are backdated and materialised at the development level they would plausibly have
 * reached by now, so the universe looks like it has been running for months rather than like it
 * was populated a minute ago.
 */
#[Description('Spawn NPC (bot) player accounts with backdated history and age-scaled progression.')]
#[Signature('ogamex:bots:spawn
                            {--count= : Number of bots to spawn. Defaults to the bots.population config value.}
                            {--persona= : Spawn only this persona instead of the configured universe mix.}
                            {--near-humans= : Fraction (0..1) placed near existing human players. Defaults to config.}')]
class SpawnBots extends Command
{
    use ReadsScalarOptions;

    /**
     * Highest planet position in a system.
     */
    private const MAX_POSITION = 15;

    public function __construct(
        private readonly BotNameGenerator $nameGenerator,
        private readonly BotPersonaRoller $personaRoller,
        private readonly BotProgression $progression,
        private readonly BotSynchroniser $synchroniser,
        private readonly PlayerServiceFactory $playerServiceFactory,
        private readonly PlanetServiceFactory $planetServiceFactory,
        private readonly SettingsService $settingsService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = $this->resolveCount();
        if ($count < 1) {
            $this->error('Nothing to spawn: count must be at least 1.');

            return self::FAILURE;
        }

        $personas = $this->resolvePersonaBatch($count);
        if ($personas === []) {
            $this->error('Could not build a persona batch. Check the bots.mix configuration.');

            return self::FAILURE;
        }

        $this->info(sprintf('Spawning %d NPC accounts...', count($personas)));

        $spawned = [];
        $failed = 0;
        $progressBar = $this->output->createProgressBar(count($personas));
        $progressBar->start();

        foreach ($personas as $persona) {
            try {
                $this->spawnBot($persona);
                $spawned[$persona->value] = ($spawned[$persona->value] ?? 0) + 1;
            } catch (Throwable $e) {
                $failed++;
                // Keep going: one bad coordinate or name collision should not abort a 200-account spawn.
                $this->newLine();
                $this->warn(sprintf('Failed to spawn a %s: %s', $persona->value, $e->getMessage()));
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        ksort($spawned);
        $rows = [];
        foreach ($spawned as $persona => $number) {
            $rows[] = [$persona, $number];
        }
        $this->table(['Persona', 'Spawned'], $rows);

        if ($failed > 0) {
            $this->warn(sprintf('%d account(s) could not be created.', $failed));
        }

        $this->info(sprintf('Done. %d NPC accounts now exist in total.', BotProfile::count()));

        if (!config('bots.enabled')) {
            $this->newLine();
            $this->warn('BOTS_ENABLED is false, so these accounts exist but will not act. Set BOTS_ENABLED=true to let them play.');
        }

        return self::SUCCESS;
    }

    /**
     * Create a single bot account, its homeworld, colonies, technologies and profile.
     *
     * @throws Throwable
     */
    private function spawnBot(BotPersona $persona): void
    {
        $config = $persona->config();
        $traits = $this->personaRoller->roll($persona);
        $ageDays = $this->rollAccountAge();

        $user = $this->createUser($persona, $traits, $ageDays);

        $player = $this->playerServiceFactory->make($user->id, true);

        // Homeworld. Some bots are placed near human players so a solo player's neighbourhood
        // is populated; the rest go wherever the normal registration logic would put them.
        $nearHumans = $this->shouldPlaceNearHumans();
        $homeworld = null;

        if ($nearHumans) {
            $coordinate = $this->findCoordinateNearHumans();
            if ($coordinate !== null) {
                $homeworld = $this->planetServiceFactory->createPlanetAtPosition($player, $coordinate, $this->rollPlanetName(true));
                $player->load($player->getId());
            }
        }

        if ($homeworld === null) {
            $homeworld = $this->planetServiceFactory->createInitialPlanetForPlayer($player, $this->rollPlanetName(true));
        }

        $techLevels = $this->progression->techConfig($persona, $ageDays, $traits['skill']);
        $planetConfig = $this->progression->planetConfig($persona, $ageDays, $traits['skill']);

        // Requirements run in both directions: technologies need buildings (a research lab), and
        // buildings need technologies (a nano factory needs computer technology 10). Reconcile
        // both ways, keeping the higher level wherever the two disagree, then re-run the
        // building pass because the technologies just added may themselves need buildings.
        $planetConfig = $this->progression->mergeHighest($planetConfig, $this->progression->buildingRequirementsForTech($techLevels));
        $techLevels = $this->progression->mergeHighest($techLevels, $this->progression->researchRequirementsForBuildings($planetConfig));
        $planetConfig = $this->progression->mergeHighest($planetConfig, $this->progression->buildingRequirementsForTech($techLevels));

        // A planet whose building levels exceed its fields is a state the game cannot produce,
        // and its building queue would reject everything.
        $planetConfig = $this->progression->fitToFields($planetConfig, $homeworld->getPlanetFieldMax());

        $this->createUserTech($user, $techLevels);
        $this->applyPlanetConfig($homeworld->getPlanetId(), $planetConfig);

        $user->planet_current = $homeworld->getPlanetId();
        $user->save();

        // Colonies. These are smaller than the homeworld, as real colonies are.
        $this->createColonies($user->id, $persona, $ageDays, $traits['skill']);

        $this->createProfile($user, $persona, $traits, $config);

        // Recalculate production and storage from the levels just written, so the account is
        // immediately consistent rather than waiting for its first tick.
        $player = $this->playerServiceFactory->make($user->id, true);
        foreach ($player->planets->all() as $planet) {
            $planet->updateResourceProductionStats(false);
            $planet->updateResourceStorageStats(false);
            $planet->save();

            $this->balanceEnergy($planet, $traits['skill']);
            $this->clampResourcesToStorage($planet->getPlanetId(), $planet, $traits['skill']);
        }
    }

    /**
     * Raise the solar plant until the planet is not running an energy deficit.
     *
     * Mine energy consumption grows faster than solar output at the same level, so scaling both
     * from the same progression factor leaves every spawned planet energy-starved. A planet in
     * deficit has its whole production scaled down, and the brain then correctly spends nearly
     * every decision digging itself out — which looks like a bot that never develops.
     *
     * The loop uses the game's own production maths rather than reimplementing the formula, so
     * it stays correct if the numbers are ever rebalanced. Careless players are left with a
     * small deficit on purpose: running slightly under-powered is a very human mistake.
     */
    private function balanceEnergy(PlanetService $planetService, float $skill): void
    {
        // A skilled player keeps a margin; a careless one runs at a deficit and does not notice.
        $target = $skill > 0.5 ? 0 : -150;

        for ($step = 0; $step < 25; $step++) {
            if ($planetService->energy()->get() >= $target) {
                return;
            }

            // Never push a planet past its field limit to fix energy.
            if ($planetService->getBuildingCount() >= $planetService->getPlanetFieldMax()) {
                return;
            }

            $planet = Planet::find($planetService->getPlanetId());
            if ($planet === null) {
                return;
            }

            $planet->solar_plant = (int) $planet->solar_plant + 1;
            $planet->save();

            $planetService->reloadPlanet();
            $planetService->updateResourceProductionStats(false);
            $planetService->save();
        }
    }

    /**
     * Bring a freshly spawned planet's resources within what its storage can actually hold.
     *
     * The progression model works out a plausible stockpile from the account's age and skill,
     * but it does not know how much storage that account built. A planet spawned above its
     * capacity is not illegal, yet it is stuck: production is clamped at the storage ceiling, so
     * the planet earns nothing until something is spent, and a newly spawned bot looks frozen.
     *
     * Filling to a fraction of capacity also reads better. A careless player sits near the top of
     * their stores with production going to waste; a careful one keeps room to spare.
     */
    private function clampResourcesToStorage(int $planetId, PlanetService $planetService, float $skill): void
    {
        $planet = Planet::find($planetId);
        if ($planet === null) {
            return;
        }

        // 0.35 of capacity for a careful player, up to 0.95 for one who never spends.
        $fillTarget = 0.95 - (0.6 * $skill);

        $ceilings = [
            'metal' => $planetService->metalStorage()->get() * $fillTarget,
            'crystal' => $planetService->crystalStorage()->get() * $fillTarget,
            'deuterium' => $planetService->deuteriumStorage()->get() * $fillTarget,
        ];

        foreach ($ceilings as $resource => $ceiling) {
            if ($ceiling > 0 && $planet->{$resource} > $ceiling) {
                $planet->{$resource} = (int) floor($ceiling);
            }
        }

        $planet->save();
    }

    /**
     * Create the user record for a bot.
     *
     * @param array{skill: float, aggression: float, risk_tolerance: float, timezone: string, activity_profile: array<string, mixed>} $traits
     * @throws Throwable
     */
    private function createUser(BotPersona $persona, array $traits, int $ageDays): User
    {
        $config = $persona->config();
        $registeredAt = Date::now()->subDays($ageDays)->subMinutes(random_int(0, 1439));

        $user = new User();
        $user->username = $this->nameGenerator->generate();
        $user->email = Str::lower(Str::random(16)) . '@' . (string) config('bots.email_domain', 'npc.invalid');
        // Bots never log in through the web interface. The password is random and discarded so
        // there is no shared credential across NPC accounts.
        $user->password = Hash::make(Str::random(64));
        $user->lang = $this->rollLanguage();

        // isset() already excludes a null character_class, which is how a persona says
        // "this account never picked a class".
        $characterClass = isset($config['character_class'])
            ? CharacterClass::tryFrom((int) $config['character_class'])
            : null;
        $user->character_class = $characterClass?->value;
        $user->character_class_free_used = $characterClass !== null;
        $user->character_class_changed_at = $characterClass !== null ? $registeredAt : null;
        // Bots must never be shown the first-login class selection flow.
        $user->first_login = false;

        $user->dark_matter = random_int(0, 20000);
        $user->register_time = (string) $registeredAt->timestamp;
        $user->time = (string) $this->rollLastActivity($persona, $registeredAt, $ageDays)->timestamp;

        $user->save();

        // The synthetic IP is derived from the user id, so it can only be set after the insert.
        $user->register_ip = $this->synchroniser->syntheticIp($user->id);
        $user->last_ip = $user->register_ip;
        // Backdate the registration so the population looks like it accumulated over months.
        // Planet timestamps are deliberately left at "now": the planet state is materialised to
        // match the account's age, so replaying production from the registration date would
        // double-count it. updated_at is not backdated because Laravel rewrites it on save.
        $user->created_at = $registeredAt;

        if ($persona === BotPersona::Vacationer && random_int(1, 100) <= (int) round(100 * (float) ($config['vacation_chance'] ?? 0.0))) {
            $user->vacation_mode = true;
            $user->vacation_mode_activated_at = Date::now()->subDays(random_int(1, 10));
        }

        $user->save();

        return $user;
    }

    /**
     * Work out when this account was last active.
     *
     * Ghosts stopped playing part-way through their lifetime and never came back, which is what
     * makes them drift into the (i) and (I) markers in the galaxy view. Everyone else is current.
     */
    private function rollLastActivity(BotPersona $persona, Carbon $registeredAt, int $ageDays): Carbon
    {
        if ($persona !== BotPersona::Ghost) {
            return Date::now()->subMinutes(random_int(0, 240));
        }

        $config = $persona->config();
        /** @var array<int, float> $range */
        $range = is_array($config['abandoned_after'] ?? null) ? $config['abandoned_after'] : [0.2, 0.7];
        $min = (float) ($range[0] ?? 0.2);
        $max = (float) ($range[1] ?? 0.7);
        $fraction = $min + (random_int(0, 100) / 100) * max(0.0, $max - $min);

        return $registeredAt->copy()->addDays((int) round($ageDays * $fraction));
    }

    /**
     * Create the tech record for a bot.
     *
     * @param array<string, int> $techLevels
     */
    private function createUserTech(User $user, array $techLevels): void
    {
        // PlayerService::load() already creates an empty tech row the first time a player is
        // loaded, and the homeworld is created through a PlayerService. Inserting another row
        // here would leave two: User::tech() returns the first, so every technology written to
        // the second would be invisible and every bot would read as having no research at all.
        $userTech = UserTech::firstOrNew(['user_id' => $user->id]);

        foreach ($techLevels as $machineName => $level) {
            $userTech->{$machineName} = $level;
        }

        $userTech->save();
    }

    /**
     * Write building levels, units and resources onto a planet.
     *
     * @param array<string, int> $config
     */
    private function applyPlanetConfig(int $planetId, array $config): void
    {
        $planet = Planet::find($planetId);
        if ($planet === null) {
            throw new RuntimeException('Planet ' . $planetId . ' not found while applying bot progression.');
        }

        foreach ($config as $column => $value) {
            $planet->{$column} = $value;
        }

        $planet->save();
    }

    /**
     * Create the bot's colonies, if its persona and age call for any.
     */
    private function createColonies(int $userId, BotPersona $persona, int $ageDays, float $skill): void
    {
        $target = $this->progression->planetCount($persona, $ageDays, $skill);

        for ($i = 1; $i < $target; $i++) {
            $player = $this->playerServiceFactory->make($userId, true);
            $coordinate = $this->planetServiceFactory->determineNewPlanetPosition();

            if ($coordinate->galaxy === 0 || $coordinate->system === 0 || $coordinate->position === 0) {
                break;
            }

            $colony = $this->planetServiceFactory->createAdditionalPlanetForPlayer($player, $coordinate);

            // Colonies are younger than the homeworld and therefore less developed. Treating a
            // colony as a fraction of the account's age is a rough model, but it produces the
            // right shape: a big main planet and progressively smaller colonies.
            $colonyAge = max(1, (int) round($ageDays * (0.6 / $i)));
            $config = $this->progression->fitToFields(
                $this->progression->planetConfig($persona, $colonyAge, $skill),
                $colony->getPlanetFieldMax(),
            );

            $this->applyPlanetConfig($colony->getPlanetId(), $config);

            if (random_int(1, 100) <= 55) {
                $planet = Planet::find($colony->getPlanetId());
                if ($planet !== null) {
                    $planet->name = $this->rollPlanetName(false);
                    $planet->save();
                }
            }
        }
    }

    /**
     * Create the bot profile that will drive this account.
     *
     * @param array{skill: float, aggression: float, risk_tolerance: float, timezone: string, activity_profile: array<string, mixed>} $traits
     * @param array<string, mixed> $config
     */
    private function createProfile(User $user, BotPersona $persona, array $traits, array $config): void
    {
        $profile = new BotProfile();
        $profile->user_id = $user->id;
        $profile->persona = $persona;
        $profile->skill = $traits['skill'];
        $profile->aggression = $traits['aggression'];
        $profile->risk_tolerance = $traits['risk_tolerance'];
        $profile->timezone = $traits['timezone'];
        $profile->activity_profile = $traits['activity_profile'];
        $profile->state = [];
        $profile->lod = $persona->isDormant() ? BotLod::Dormant : BotLod::Full;
        $profile->enabled = true;
        // Ghosts never act. Everyone else is due shortly, spread over the next hour so the whole
        // population does not wake up in the same minute.
        $profile->next_action_at = $persona->isDormant()
            ? null
            : Date::now()->addMinutes(random_int(1, 60));
        $profile->save();
    }

    /**
     * Decide how many bots to spawn.
     */
    private function resolveCount(): int
    {
        return $this->intOption('count') ?? (int) config('bots.population', 200);
    }

    /**
     * Build the list of personas to spawn, honouring --persona or the configured mix.
     *
     * @return array<int, BotPersona>
     */
    private function resolvePersonaBatch(int $count): array
    {
        $only = $this->scalarOption('persona');

        if ($only !== null) {
            $persona = BotPersona::tryFrom($only);
            if ($persona === null) {
                $this->error(sprintf('Unknown persona "%s". Valid values: %s', $only, implode(', ', array_column(BotPersona::cases(), 'value'))));

                return [];
            }

            return array_fill(0, $count, $persona);
        }

        /** @var array<string, int> $mix */
        $mix = config('bots.mix', []);
        $weighted = [];

        foreach ($mix as $value => $weight) {
            $persona = BotPersona::tryFrom((string) $value);
            if ($persona === null || $weight < 1) {
                continue;
            }

            for ($i = 0; $i < (int) $weight; $i++) {
                $weighted[] = $persona;
            }
        }

        if ($weighted === []) {
            return [];
        }

        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $batch[] = $weighted[random_int(0, count($weighted) - 1)];
        }

        return $batch;
    }

    /**
     * Roll how long ago this account "registered".
     */
    private function rollAccountAge(): int
    {
        $min = max(1, (int) config('bots.account_age_days.min', 2));
        $max = max($min, (int) config('bots.account_age_days.max', 180));

        return random_int($min, $max);
    }

    /**
     * Decide whether this bot should be placed near a human player.
     */
    private function shouldPlaceNearHumans(): bool
    {
        $share = $this->floatOption('near-humans')
            ?? (float) config('bots.placement.human_proximity_share', 0.35);

        if ($share <= 0) {
            return false;
        }

        return (random_int(1, 1000) / 1000) <= $share;
    }

    /**
     * Find a free coordinate within a configurable radius of a human player's planet.
     *
     * Returns null when there are no human players yet, or when no free slot could be found, in
     * which case the caller falls back to the normal registration placement.
     */
    private function findCoordinateNearHumans(): Coordinate|null
    {
        $anchor = Planet::query()
            ->whereNotIn('user_id', BotProfile::pluck('user_id'))
            ->inRandomOrder()
            ->first();

        if ($anchor === null) {
            return null;
        }

        $radius = max(1, (int) config('bots.placement.human_proximity_systems', 25));
        $maxGalaxies = max(1, $this->settingsService->numberOfGalaxies());
        $galaxy = min($anchor->galaxy, $maxGalaxies);

        // Try random nearby slots rather than scanning in order, so bots do not pile up into a
        // solid block of consecutive systems next to the first human they find.
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $system = $anchor->system + random_int(-$radius, $radius);
            if ($system < 1) {
                continue;
            }

            $coordinate = new Coordinate($galaxy, $system, random_int(1, self::MAX_POSITION));

            if (!$this->planetServiceFactory->planetExistsAtCoordinate($coordinate)) {
                return $coordinate;
            }
        }

        return null;
    }

    /**
     * Pick a planet name.
     *
     * Most players rename their homeworld and about half their colonies, and some never rename
     * anything at all, so the default names are left in place a fair share of the time.
     */
    private function rollPlanetName(bool $isHomeworld): string
    {
        $names = $isHomeworld
            ? ['Homeworld', 'Homeworld', 'Origin', 'Terra', 'Prime', 'Capital', 'First Light', 'Anchor', 'Cradle']
            : ['Colony', 'Colony', 'Outpost', 'Mining Post', 'Forge', 'Depot', 'Waypoint', 'Far Reach', 'Refinery'];

        return $names[random_int(0, count($names) - 1)];
    }

    /**
     * Pick an interface language for the account, weighted towards the languages the game ships.
     */
    private function rollLanguage(): string
    {
        $languages = ['en', 'en', 'en', 'en', 'en', 'en', 'de', 'nl', 'it'];

        return $languages[random_int(0, count($languages) - 1)];
    }
}
