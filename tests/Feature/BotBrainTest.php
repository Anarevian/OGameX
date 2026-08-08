<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
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
        // Developed, because these tests exercise behaviour that needs ships and technology
        // already in place. Bootstrapping from nothing is covered in BotSpawnTest.
        $this->assertArtisanSucceeds('ogamex:bots:spawn', [
            '--count' => 1,
            '--persona' => $persona,
            '--near-humans' => '0',
            '--developed' => true,
        ]);

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

        $this->assertArtisanSucceeds('ogamex:bots:tick', ['--sync' => true]);

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
            $this->assertArtisanSucceeds('ogamex:bots:tick', ['--sync' => true]);
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

        $before = $this->totalMineLevels($playerServiceFactory, $profile->user_id);

        // Seven simulated days, ticking every few hours.
        for ($i = 0; $i < 40; $i++) {
            $this->assertArtisanSucceeds('ogamex:bots:tick', ['--sync' => true]);
            Date::setTestNow(Date::now()->addHours(4));
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $after = $this->totalMineLevels($playerServiceFactory, $profile->user_id);

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

        $this->assertArtisanSucceeds('ogamex:bots:tick', ['--sync' => true]);

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

        $this->assertArtisanSucceeds('ogamex:bots:tick', ['--sync' => true]);

        $this->assertSame(0, BotActionLog::where('bot_user_id', $profile->user_id)->count());
    }

    /**
     * Nothing happens while the master switch is off.
     */
    public function testDisabledBotsDoNotAct(): void
    {
        $profile = $this->forceAwake($this->spawnOne('miner'));

        config(['bots.enabled' => false]);

        $this->assertArtisanSucceeds('ogamex:bots:tick', ['--sync' => true]);

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
            $this->assertArtisanSucceeds('ogamex:bots:tick', ['--sync' => true]);
            Date::setTestNow(Date::now()->addHours(6));
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $turtleDefence = BotActionLog::where('action', 'build_defence')->count();
        $turtleTotal = max(1, BotActionLog::count());
        $turtleShare = $turtleDefence / $turtleTotal;

        $this->removeAllBots();
        Date::setTestNow();
        BotActionLog::query()->delete();

        $this->forceAwake($this->spawnOne('miner'));

        for ($i = 0; $i < 25; $i++) {
            $this->assertArtisanSucceeds('ogamex:bots:tick', ['--sync' => true]);
            Date::setTestNow(Date::now()->addHours(6));
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $minerDefence = BotActionLog::where('action', 'build_defence')->count();
        $minerTotal = max(1, BotActionLog::count());
        $minerShare = $minerDefence / $minerTotal;

        $this->assertGreaterThan(
            $minerShare,
            $turtleShare,
            sprintf(
                'A turtle must spend a larger share of its decisions on defence than a miner (turtle %.2f, miner %.2f).',
                $turtleShare,
                $minerShare
            )
        );
    }

    /**
     * The decision log is prunable, so it cannot grow without bound.
     */
    public function testActionLogPruning(): void
    {
        $profile = $this->forceAwake($this->spawnOne('miner'));

        $old = BotActionLog::create([
            'bot_user_id' => $profile->user_id,
            'action' => 'build_building',
            'succeeded' => true,
            'reason' => 'old entry',
        ]);
        // created_at is not fillable, so it has to be backdated after the insert.
        $old->created_at = Date::now()->subDays(40);
        $old->save();
        BotActionLog::create([
            'bot_user_id' => $profile->user_id,
            'action' => 'build_building',
            'succeeded' => true,
            'reason' => 'recent entry',
        ]);

        $this->assertArtisanSucceeds('ogamex:bots:prune-log', ['--days' => 14]);

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

        $this->assertArtisanSucceeds('ogamex:bots:tick', ['--user' => (string) $profile->user_id]);

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

            $this->assertArtisanSucceeds('ogamex:bots:tick', ['--sync' => true]);

            $this->assertSame(
                0,
                BotActionLog::where('succeeded', false)->count(),
                sprintf('Persona %s produced a failed action.', $persona->value)
            );
        }
    }

    /**
     * Run an artisan command and assert it succeeded.
     *
     * Uses Artisan::call() rather than chaining off $this->artisan(), because that returns
     * PendingCommand|int and the union is not narrowable, which static analysis rejects.
     *
     * @param array<string, mixed> $parameters
     */
    private function assertArtisanSucceeds(string $command, array $parameters = []): void
    {
        $this->assertSame(0, Artisan::call($command, $parameters), $command . ' should succeed.');
    }

    /**
     * Sum the mine levels across every planet a bot owns.
     *
     * A bot with colonies invests wherever the payback is best, which is often not the
     * homeworld, so the empire total is the meaningful measure of economic growth.
     */
    private function totalMineLevels(PlayerServiceFactory $factory, int $userId): int
    {
        $total = 0;

        foreach ($factory->make($userId, true)->planets->all() as $planet) {
            $total += $planet->getObjectLevel('metal_mine')
                + $planet->getObjectLevel('crystal_mine')
                + $planet->getObjectLevel('deuterium_synthesizer');
        }

        return $total;
    }
}
