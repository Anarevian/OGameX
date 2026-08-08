<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Perception\BotBattleObserver;
use OGame\Bots\Perception\BotMemoryService;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BattleReport;
use OGame\Models\BotIntel;
use OGame\Models\BotMemory;
use OGame\Models\BotProfile;
use OGame\Models\User;
use Tests\TestCase;

/**
 * Phase 3 coverage: what a bot remembers about other players, and what it does about it.
 *
 * Because bots never send messages, memory is the entirety of their social life — every
 * relationship they have is expressed through actions, and those actions need something to be
 * based on.
 */
class BotMemoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['bots.enabled' => true]);
        $this->removeAllBots();
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

    private function spawnOne(string $persona = 'raider'): BotProfile
    {
        $this->assertSame(0, Artisan::call('ogamex:bots:spawn', [
            '--count' => 1,
            '--persona' => $persona,
            '--near-humans' => '0',
            '--developed' => true,
        ]));

        return BotProfile::firstOrFail();
    }

    /**
     * A stranger is neutral, and asking about one does not manufacture a relationship.
     */
    public function testStrangersAreNeutral(): void
    {
        $profile = $this->spawnOne();
        $other = User::whereNotIn('id', BotProfile::pluck('user_id'))->firstOrFail();

        $memoryService = resolve(BotMemoryService::class);

        $this->assertSame(0, $memoryService->attitudeTowards($profile->user_id, $other->id));
        $this->assertSame(0, BotMemory::where('bot_user_id', $profile->user_id)->count());
    }

    /**
     * Being attacked creates a grudge, and the size of it scales with what the attack cost.
     */
    public function testBeingAttackedCreatesAProportionalGrudge(): void
    {
        $profile = $this->spawnOne();
        $attacker = User::whereNotIn('id', BotProfile::pluck('user_id'))->firstOrFail();

        $memoryService = resolve(BotMemoryService::class);

        $memoryService->recordAttackSuffered($profile->user_id, $attacker->id, 10000);
        $smallRaid = $memoryService->attitudeTowards($profile->user_id, $attacker->id);

        $this->assertLessThan(0, $smallRaid, 'Being attacked must worsen the bot\'s attitude.');

        // A second, far costlier attack should hurt considerably more.
        $memoryService->recordAttackSuffered($profile->user_id, $attacker->id, 2000000);
        $afterBigRaid = $memoryService->attitudeTowards($profile->user_id, $attacker->id);

        $this->assertLessThan($smallRaid, $afterBigRaid);

        $memory = BotMemory::where('bot_user_id', $profile->user_id)->firstOrFail();
        $this->assertSame(2, $memory->attacked_us_count);
        $this->assertTrue($memory->isHostile(), 'Two costly raids must make the bot hostile.');
    }

    /**
     * Attitude is clamped, so no amount of provocation can push it out of range.
     */
    public function testAttitudeStaysWithinRange(): void
    {
        $profile = $this->spawnOne();
        $attacker = User::whereNotIn('id', BotProfile::pluck('user_id'))->firstOrFail();

        $memoryService = resolve(BotMemoryService::class);

        for ($i = 0; $i < 20; $i++) {
            $memoryService->recordAttackSuffered($profile->user_id, $attacker->id, 5000000);
        }

        $this->assertGreaterThanOrEqual(-100, $memoryService->attitudeTowards($profile->user_id, $attacker->id));
    }

    /**
     * Grudges cool over time, or the universe would calcify into permanent feuds.
     */
    public function testGrudgesDecayWhenLeftAlone(): void
    {
        $profile = $this->spawnOne();
        $attacker = User::whereNotIn('id', BotProfile::pluck('user_id'))->firstOrFail();

        $memoryService = resolve(BotMemoryService::class);
        $memoryService->recordAttackSuffered($profile->user_id, $attacker->id, 500000);

        $before = $memoryService->attitudeTowards($profile->user_id, $attacker->id);
        $this->assertLessThan(0, $before);

        // Months pass with no further contact.
        Date::setTestNow(Date::now()->addDays(60));

        for ($i = 0; $i < 10; $i++) {
            $memoryService->decayGrudges($profile->user_id);
        }

        $after = $memoryService->attitudeTowards($profile->user_id, $attacker->id);

        $this->assertGreaterThan($before, $after, 'An old grudge must fade towards neutral.');
    }

    /**
     * A battle report the bot lost becomes a grudge against whoever sent the fleet.
     */
    public function testLosingABattleRemembersTheAttacker(): void
    {
        $profile = $this->spawnOne();
        $attacker = User::whereNotIn('id', BotProfile::pluck('user_id'))->firstOrFail();

        $report = new BattleReport();
        $report->planet_galaxy = 1;
        $report->planet_system = 77;
        $report->planet_position = 6;
        $report->planet_user_id = $profile->user_id;
        $report->attacker = ['player_id' => $attacker->id, 'resource_loss' => 1000, 'units' => []];
        $report->defender = ['player_id' => $profile->user_id, 'resource_loss' => 250000, 'units' => []];
        $report->loot = ['metal' => 100000, 'crystal' => 50000, 'deuterium' => 0];
        $report->save();

        resolve(BotBattleObserver::class)->absorb($profile, $report);

        $this->assertLessThan(
            0,
            resolve(BotMemoryService::class)->attitudeTowards($profile->user_id, $attacker->id),
            'A bot must remember who took its resources.'
        );
    }

    /**
     * A battle the bot won teaches it what the defender actually had, which is better than any
     * espionage report it was working from.
     */
    public function testWinningABattleUpdatesIntel(): void
    {
        $profile = $this->spawnOne();
        $victim = User::whereNotIn('id', BotProfile::pluck('user_id'))->firstOrFail();

        $report = new BattleReport();
        $report->planet_galaxy = 2;
        $report->planet_system = 44;
        $report->planet_position = 9;
        $report->planet_user_id = $victim->id;
        $report->attacker = ['player_id' => $profile->user_id, 'resource_loss' => 5000, 'units' => []];
        $report->defender = ['player_id' => $victim->id, 'resource_loss' => 90000, 'units' => ['light_fighter' => 40]];
        $report->loot = ['metal' => 200000, 'crystal' => 100000, 'deuterium' => 10000];
        $report->save();

        resolve(BotBattleObserver::class)->absorb($profile, $report);

        $intel = BotIntel::where('bot_user_id', $profile->user_id)
            ->where('system', 44)
            ->where('position', 9)
            ->firstOrFail();

        $this->assertSame('battle', $intel->source);
        $this->assertSame($victim->id, $intel->owner_user_id);

        $payload = $intel->payload;
        $this->assertIsArray($payload);
        $this->assertSame(40, $payload['ship_total'], 'The fight revealed the defender\'s ships.');
        // The planet was just looted, so the bot should not believe there is anything left.
        $this->assertSame(0, $payload['metal']);
    }

    /**
     * Enemies are listed worst-first, which is what revenge targeting reads.
     */
    public function testEnemiesAreRankedByHowBadlyTheyBehaved(): void
    {
        $profile = $this->spawnOne();

        $others = User::whereNotIn('id', BotProfile::pluck('user_id'))->limit(2)->get();
        if ($others->count() < 2) {
            $this->markTestSkipped('Needs two non-bot accounts to rank.');
        }

        $memoryService = resolve(BotMemoryService::class);

        $mild = $others->firstOrFail();
        $severe = $others->last();
        $this->assertNotNull($severe);

        $memoryService->recordAttackSuffered($profile->user_id, $mild->id, 200000);
        $memoryService->recordAttackSuffered($profile->user_id, $severe->id, 5000000);

        $enemies = $memoryService->enemies($profile->user_id);

        $this->assertNotEmpty($enemies);
        $this->assertSame($severe->id, $enemies[0], 'The worst offender must rank first.');
    }
}
