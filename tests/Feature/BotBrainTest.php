<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use OGame\Bots\Activity\ActivityScheduler;
use OGame\Enums\BotPersona;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BotActionLog;
use OGame\Models\BotProfile;
use Tests\TestCase;

/**
 * Phase 1 coverage: the tick driver, session pacing and the economy brain.
 */
class BotBrainTest extends TestCase
{
    /**
     * The suite does not roll the database back between tests.
     */
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
        $playerServiceFactory = resolve(PlayerServiceFactory::class);

        foreach (BotProfile::pluck('user_id') as $userId) {
            $playerServiceFactory->make((int) $userId, true)->delete();
        }
    }

    /**
     * Spawn a single bot of the given persona and return its profile.
     */
    private function spawnOne(string $persona): BotProfile
    {
        $this->artisan('ogamex:bots:spawn', ['--count' => 1, '--persona' => $persona, '--near-humans' => '0'])
            ->assertSuccessful();

        return BotProfile::firstOrFail();
    }

    /**
     * Force a bot to be awake right now, so a test does not depend on the clock.
     */
    private function forceAwake(BotProfile $profile): BotProfile
    {
        $profile->activity_profile = array_merge($profile->activity_profile, ['awake_hours' => [0, 24]]);
        $profile->next_action_at = Date::now()->subMinute();
        $profile->save();

        return $profile;
    }

    /**
     * A ticked bot actually does something, and records why.
     */
    public function testTickProducesLoggedDecisions(): void
    {
        $profile = $this->forceAwake($this->spawnOne('miner'));

        $this->artisan('ogamex:bots:tick', ['--sync' => true])->assertSuccessful();

        $log = BotActionLog::where('bot_user_id', $profile->user_id)->get();

        $this->assertGreaterThan(0, $log->count(), 'A woken bot must take at least one action.');

        foreach ($log as $entry) {
            $this->assertNotEmpty($entry->reason, 'Every decision must record why it was taken.');
            $this->assertNotNull($entry->tick_id, 'Decisions must be grouped by tick.');
        }
    }

    /**
     * Bots must not take actions that the game rejects. A failed action means the bot proposed
     * something it could not actually do, which is the main thing that can silently go wrong.
     */
    public function testTickedBotTakesNoIllegalActions(): void
    {
        $this->forceAwake($this->spawnOne('miner'));

        for ($i = 0; $i < 5; $i++) {
            $this->artisan('ogamex:bots:tick', ['--sync' => true])->assertSuccessful();
            Date::setTestNow(Date::now()->addHours(4));
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $failures = BotActionLog::where('succeeded', false)->get();

        $this->assertCount(
            0,
            $failures,
            'Bot proposed an action the game rejected: ' . $failures->pluck('payload')->toJson()
        );
    }

    /**
     * Over simulated days a miner's economy visibly grows.
     */
    public function testMinerDevelopsItsEconomyOverTime(): void
    {
        $profile = $this->forceAwake($this->spawnOne('miner'));
        $playerServiceFactory = resolve(PlayerServiceFactory::class);

        $before = $playerServiceFactory->make($profile->user_id, true)
            ->planets->current()->getObjectLevel('metal_mine');

        // Seven simulated days, ticking every few hours.
        for ($i = 0; $i < 40; $i++) {
            $this->artisan('ogamex:bots:tick', ['--sync' => true])->assertSuccessful();
            Date::setTestNow(Date::now()->addHours(4));
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $after = $playerServiceFactory->make($profile->user_id, true)
            ->planets->current()->getObjectLevel('metal_mine');

        $this->assertGreaterThan($before, $after, 'A miner must raise its mines over a week of play.');
    }

    /**
     * A sleeping bot is synchronised but takes no actions, which is what makes the population's
     * activity look like people in different timezones rather than a server-wide heartbeat.
     */
    public function testSleepingBotTakesNoActions(): void
    {
        $profile = $this->spawnOne('miner');
        $profile->activity_profile = array_merge($profile->activity_profile, ['awake_hours' => [0, 0]]);
        $profile->next_action_at = Date::now()->subMinute();
        $profile->save();

        $this->artisan('ogamex:bots:tick', ['--sync' => true])->assertSuccessful();

        $this->assertSame(0, BotActionLog::where('bot_user_id', $profile->user_id)->count());

        // It must still have been rescheduled, or it would tick every minute forever.
        $profile->refresh();
        $this->assertNotNull($profile->last_tick_at);
    }

    /**
     * Ghosts are never dispatched, no matter how overdue they look.
     */
    public function testGhostsAreNeverTicked(): void
    {
        $profile = $this->spawnOne('ghost');
        $profile->next_action_at = Date::now()->subDay();
        $profile->save();

        $this->artisan('ogamex:bots:tick', ['--sync' => true])->assertSuccessful();

        $this->assertSame(0, BotActionLog::where('bot_user_id', $profile->user_id)->count());
    }

    /**
     * Nothing happens while the master switch is off.
     */
    public function testDisabledBotsDoNotAct(): void
    {
        $profile = $this->forceAwake($this->spawnOne('miner'));

        config(['bots.enabled' => false]);

        $this->artisan('ogamex:bots:tick', ['--sync' => true])->assertSuccessful();

        $this->assertSame(0, BotActionLog::where('bot_user_id', $profile->user_id)->count());
    }

    /**
     * The scheduler never parks an active bot outside its waking hours.
     */
    public function testSchedulerRespectsWakingHours(): void
    {
        $profile = $this->spawnOne('casual');
        $scheduler = resolve(ActivityScheduler::class);

        /** @var array<int, int> $hours */
        $hours = $profile->activity_profile['awake_hours'];
        $start = (int) $hours[0];
        $end = (int) $hours[1];

        for ($i = 0; $i < 25; $i++) {
            $next = $scheduler->nextSessionStart($profile);
            $this->assertNotNull($next);

            $localHour = (int) $next->copy()->setTimezone($profile->timezone)->format('G');

            $awake = $end <= 24
                ? ($localHour >= $start && $localHour < $end)
                : ($localHour >= $start || $localHour < ($end - 24));

            $this->assertTrue(
                $awake,
                sprintf('Scheduled %02d:00 local, outside the %02d-%02d waking window.', $localHour, $start, $end)
            );
        }
    }

    /**
     * Personas diverge: a turtle builds defence, a miner does not.
     */
    public function testPersonasProduceDifferentBehaviour(): void
    {
        $this->forceAwake($this->spawnOne('turtle'));

        for ($i = 0; $i < 25; $i++) {
            $this->artisan('ogamex:bots:tick', ['--sync' => true])->assertSuccessful();
            Date::setTestNow(Date::now()->addHours(6));
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $turtleDefence = BotActionLog::where('action', 'build_defence')->count();

        $this->removeAllBots();
        Date::setTestNow();
        BotActionLog::query()->delete();

        $this->forceAwake($this->spawnOne('miner'));

        for ($i = 0; $i < 25; $i++) {
            $this->artisan('ogamex:bots:tick', ['--sync' => true])->assertSuccessful();
            Date::setTestNow(Date::now()->addHours(6));
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $minerDefence = BotActionLog::where('action', 'build_defence')->count();

        $this->assertGreaterThan(
            $minerDefence,
            $turtleDefence,
            'A turtle must build more defence than a miner over the same period.'
        );
    }

    /**
     * The decision log is prunable, so it cannot grow without bound.
     */
    public function testActionLogPruning(): void
    {
        $profile = $this->forceAwake($this->spawnOne('miner'));

        BotActionLog::create([
            'bot_user_id' => $profile->user_id,
            'action' => 'build_building',
            'succeeded' => true,
            'reason' => 'old entry',
            'created_at' => Date::now()->subDays(40),
            'updated_at' => Date::now()->subDays(40),
        ]);
        BotActionLog::create([
            'bot_user_id' => $profile->user_id,
            'action' => 'build_building',
            'succeeded' => true,
            'reason' => 'recent entry',
        ]);

        $this->artisan('ogamex:bots:prune-log', ['--days' => 14])->assertSuccessful();

        $remaining = BotActionLog::where('bot_user_id', $profile->user_id)->pluck('reason');

        $this->assertContains('recent entry', $remaining->all());
        $this->assertNotContains('old entry', $remaining->all());
    }

    /**
     * Ticking a specific bot works regardless of its schedule, which the admin panel needs.
     */
    public function testForceTickASingleBot(): void
    {
        $profile = $this->forceAwake($this->spawnOne('miner'));
        $profile->next_action_at = Date::now()->addDays(3);
        $profile->save();

        $this->artisan('ogamex:bots:tick', ['--user' => (string) $profile->user_id])->assertSuccessful();

        $profile->refresh();
        $this->assertNotNull($profile->last_tick_at);
        $this->assertNotNull($profile->state['stance'] ?? null, 'A ticked bot must record its stance.');
    }

    /**
     * Every persona can be ticked without throwing, which is the cheapest guard against a
     * persona config that produces no legal actions at all.
     */
    public function testEveryPersonaCanBeTicked(): void
    {
        foreach (BotPersona::cases() as $persona) {
            $this->removeAllBots();
            BotActionLog::query()->delete();

            $profile = $this->spawnOne($persona->value);
            if (!$persona->isDormant()) {
                $this->forceAwake($profile);
            }

            $this->artisan('ogamex:bots:tick', ['--sync' => true])->assertSuccessful();

            $this->assertSame(
                0,
                BotActionLog::where('succeeded', false)->count(),
                sprintf('Persona %s produced a failed action.', $persona->value)
            );
        }
    }
}
