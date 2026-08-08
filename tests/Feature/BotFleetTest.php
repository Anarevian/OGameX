<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Perception\BotIntelWriter;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BotActionLog;
use OGame\Models\BotIntel;
use OGame\Models\BotProfile;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\TestCase;

/**
 * Phase 2 coverage: fleet dispatch, espionage, the fog of war and the fairness caps.
 */
class BotFleetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['bots.enabled' => true]);
        $this->removeAllBots();
        BotActionLog::query()->delete();
    }

    protected function tearDown(): void
    {
        $this->removeAllBots();
        Date::setTestNow();

        parent::tearDown();
    }

    private function removeAllBots(): void
    {
        $factory = resolve(PlayerServiceFactory::class);

        foreach (BotProfile::pluck('user_id') as $userId) {
            $factory->make((int) $userId, true)->delete();
        }
    }

    private function spawnOne(string $persona): BotProfile
    {
        $this->assertSame(0, Artisan::call('ogamex:bots:spawn', [
            '--count' => 1,
            '--persona' => $persona,
            '--near-humans' => '0',
        ]));

        $profile = BotProfile::firstOrFail();
        $profile->activity_profile = array_merge($profile->activity_profile, ['awake_hours' => [0, 24]]);
        $profile->next_action_at = Date::now()->subMinute();
        $profile->save();

        return $profile;
    }

    /**
     * Bots put fleets on the map. This is the single most visible sign of life in the galaxy.
     */
    public function testBotsDispatchFleetMissions(): void
    {
        $profile = $this->spawnOne('explorer');

        for ($i = 0; $i < 12; $i++) {
            Artisan::call('ogamex:bots:tick', ['--sync' => true]);
            Date::setTestNow(Date::now()->addHours(5));
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $planetIds = Planet::where('user_id', $profile->user_id)->pluck('id');
        $missions = FleetMission::whereIn('planet_id_from', $planetIds)->count();

        $this->assertGreaterThan(0, $missions, 'An explorer must send fleets out over time.');
    }

    /**
     * Nothing in Phase 2 may propose an action the game rejects.
     */
    public function testFleetActionsAreNeverIllegal(): void
    {
        foreach (['explorer', 'raider', 'fleeter'] as $persona) {
            $this->removeAllBots();
            BotActionLog::query()->delete();
            Date::setTestNow();

            $this->spawnOne($persona);

            for ($i = 0; $i < 8; $i++) {
                Artisan::call('ogamex:bots:tick', ['--sync' => true]);
                Date::setTestNow(Date::now()->addHours(5));
                BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
            }

            $failures = BotActionLog::where('succeeded', false)->get();

            $this->assertCount(
                0,
                $failures,
                sprintf('%s proposed a rejected action: %s', $persona, $failures->pluck('payload')->toJson())
            );
        }
    }

    /**
     * An espionage report becomes something the bot believes, and only what the report contained.
     */
    public function testEspionageReportBecomesIntel(): void
    {
        $profile = $this->spawnOne('raider');

        $victim = User::whereNotIn('id', BotProfile::pluck('user_id'))->firstOrFail();
        $victimId = $victim->id;

        $report = new EspionageReport();
        $report->planet_galaxy = 1;
        $report->planet_system = 42;
        $report->planet_position = 7;
        $report->planet_type = 1;
        $report->planet_user_id = $victimId;
        $report->resources = ['metal' => 400000, 'crystal' => 200000, 'deuterium' => 50000, 'energy' => 0];
        $report->ships = [];
        $report->defense = [];
        $report->player_info = ['player_id' => (string) $victimId, 'player_name' => 'Target'];
        $report->save();

        resolve(BotIntelWriter::class)->write($profile->user_id, $report);

        $intel = BotIntel::where('bot_user_id', $profile->user_id)
            ->where('system', 42)
            ->where('position', 7)
            ->firstOrFail();

        $this->assertSame('espionage', $intel->source);
        $this->assertSame($victimId, $intel->owner_user_id);

        $payload = $intel->payload;
        $this->assertIsArray($payload);
        $this->assertSame(400000, $payload['metal']);
        // The report showed no military, and that is exactly what was recorded.
        $this->assertFalse($payload['saw_military']);
    }

    /**
     * Intel decays, so a bot acting on an old report knows it is guessing.
     */
    public function testIntelConfidenceDecaysWithAge(): void
    {
        $profile = $this->spawnOne('raider');

        $intel = new BotIntel();
        $intel->bot_user_id = $profile->user_id;
        $intel->galaxy = 1;
        $intel->system = 50;
        $intel->position = 5;
        $intel->planet_type = 1;
        $intel->source = 'espionage';
        $intel->confidence = 1.0;
        $intel->observed_at = Date::now();
        $intel->save();

        $this->assertGreaterThan(0.9, $intel->currentConfidence());
        $this->assertFalse($intel->isStale());

        // Espionage intel halves every six hours, so a day and a half old is nearly worthless.
        Date::setTestNow(Date::now()->addHours(36));

        $this->assertLessThan(0.1, $intel->currentConfidence());
        $this->assertTrue($intel->isStale());
    }

    /**
     * The fairness caps are the only thing protecting human players, because the engine enforces
     * no newbie protection at all. A brand new human must never be raided.
     */
    public function testNewHumanPlayersAreNeverRaided(): void
    {
        $profile = $this->spawnOne('raider');

        // A freshly registered human sitting on a large, undefended pile of resources: the most
        // attractive target the scorer could possibly be offered.
        $human = User::whereNotIn('id', BotProfile::pluck('user_id'))->firstOrFail();
        $human->created_at = Date::now()->subDay();
        $human->save();

        $humanPlanet = Planet::where('user_id', $human->id)->firstOrFail();

        $intel = new BotIntel();
        $intel->bot_user_id = $profile->user_id;
        $intel->galaxy = $humanPlanet->galaxy;
        $intel->system = $humanPlanet->system;
        $intel->position = $humanPlanet->planet;
        $intel->planet_type = 1;
        $intel->source = 'espionage';
        $intel->owner_user_id = $human->id;
        $intel->payload = [
            'metal' => 5000000,
            'crystal' => 5000000,
            'deuterium' => 5000000,
            'ships' => [],
            'defence' => [],
            'saw_military' => true,
            'ship_total' => 0,
            'defence_total' => 0,
        ];
        $intel->confidence = 1.0;
        $intel->observed_at = Date::now();
        $intel->save();

        for ($i = 0; $i < 6; $i++) {
            Artisan::call('ogamex:bots:tick', ['--sync' => true]);
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $raidsOnHuman = BotActionLog::where('action', 'raid')
            ->whereJsonContains('payload->target_user_id', $human->id)
            ->count();

        $this->assertSame(0, $raidsOnHuman, 'A human inside the grace period must never be raided.');
    }

    /**
     * A bot that sees a hostile fleet inbound moves its own fleet off the planet.
     *
     * This is the clearest difference between a bot that plays and a bot that just exists.
     */
    public function testBotFleetsavesWhenUnderThreat(): void
    {
        $profile = $this->spawnOne('fleeter');
        // A perfect reactor, so the skill roll cannot make the test flaky. The roll itself is
        // covered by the fact that low-skill bots exist at all.
        $profile->skill = 1.0;
        $profile->save();

        $planets = Planet::where('user_id', $profile->user_id)->orderBy('id')->get();
        if ($planets->count() < 2) {
            $this->markTestSkipped('This bot rolled a single planet; fleetsave needs somewhere to fly to.');
        }

        $threatened = $planets->firstOrFail();
        $attacker = User::whereNotIn('id', BotProfile::pluck('user_id'))->firstOrFail();
        $attackerPlanet = Planet::where('user_id', $attacker->id)->firstOrFail();

        // An attack already in the air at the bot's planet.
        $mission = new FleetMission();
        $mission->user_id = $attacker->id;
        $mission->planet_id_from = $attackerPlanet->id;
        $mission->planet_id_to = $threatened->id;
        $mission->mission_type = 1;
        $mission->time_departure = (int) Date::now()->timestamp;
        $mission->time_arrival = (int) Date::now()->addHours(2)->timestamp;
        $mission->galaxy_from = $attackerPlanet->galaxy;
        $mission->system_from = $attackerPlanet->system;
        $mission->position_from = $attackerPlanet->planet;
        $mission->galaxy_to = $threatened->galaxy;
        $mission->system_to = $threatened->system;
        $mission->position_to = $threatened->planet;
        $mission->processed = 0;
        $mission->light_fighter = 100;
        $mission->save();

        for ($i = 0; $i < 4; $i++) {
            Artisan::call('ogamex:bots:tick', ['--sync' => true]);
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $this->assertGreaterThan(
            0,
            BotActionLog::where('bot_user_id', $profile->user_id)->where('action', 'fleetsave')->count(),
            'A skilled bot must fly its fleet away from an incoming attack.'
        );
    }

    /**
     * A bot with an overflowing planet ships the surplus somewhere it can be spent.
     */
    public function testBotTransportsResourcesBetweenItsOwnPlanets(): void
    {
        $profile = $this->spawnOne('miner');

        $planets = Planet::where('user_id', $profile->user_id)->orderBy('id')->get();
        if ($planets->count() < 2) {
            $this->markTestSkipped('This bot rolled a single planet; transport needs two.');
        }

        // Fill the first planet to the brim and give it freighters to move the surplus.
        $full = $planets->firstOrFail();
        $full->metal = 100000000;
        $full->crystal = 100000000;
        $full->large_cargo = 200;
        $full->save();

        for ($i = 0; $i < 4; $i++) {
            Artisan::call('ogamex:bots:tick', ['--sync' => true]);
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $this->assertGreaterThan(
            0,
            BotActionLog::where('bot_user_id', $profile->user_id)->where('action', 'transport')->count(),
            'A bot sitting on a full store must ship the surplus to another of its planets.'
        );
    }

    /**
     * Every Phase 2 action must be reachable without the game rejecting it, across the personas
     * most likely to use them.
     */
    public function testExtendedFleetActionsAreLegal(): void
    {
        foreach (['miner', 'turtle', 'trader'] as $persona) {
            $this->removeAllBots();
            BotActionLog::query()->delete();
            Date::setTestNow();

            $this->spawnOne($persona);

            for ($i = 0; $i < 6; $i++) {
                Artisan::call('ogamex:bots:tick', ['--sync' => true]);
                Date::setTestNow(Date::now()->addHours(6));
                BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
            }

            $failures = BotActionLog::where('succeeded', false)->get();

            $this->assertCount(
                0,
                $failures,
                sprintf('%s proposed a rejected action: %s', $persona, $failures->pluck('payload')->toJson())
            );
        }
    }
}
