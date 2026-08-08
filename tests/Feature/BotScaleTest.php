<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Scale\BotLodClassifier;
use OGame\Bots\Scale\BotMaterialiser;
use OGame\Enums\BotLod;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BotActionLog;
use OGame\Models\BotProfile;
use OGame\Models\Planet;
use Tests\TestCase;

/**
 * Phase 5 coverage: level of detail, lazy materialisation and the operational safety switches.
 *
 * The load-bearing claim of this phase is that level of detail changes only *cadence*, never
 * correctness — so most of these tests are about a bot's state still being right when someone
 * finally looks at it.
 */
class BotScaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['bots.enabled' => true]);
        Cache::forget('bots:paused');
        Cache::forget('bots:human_systems');
        $this->removeAllBots();
        BotActionLog::query()->delete();
    }

    protected function tearDown(): void
    {
        Cache::forget('bots:paused');
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

    private function spawn(int $count = 1, string $persona = 'miner'): BotProfile
    {
        $this->assertSame(0, Artisan::call('ogamex:bots:spawn', [
            '--count' => $count,
            '--persona' => $persona,
            '--near-humans' => '0',
            '--developed' => true,
        ]));

        return BotProfile::firstOrFail();
    }

    /**
     * A ghost is never worth simulating, however overdue it looks.
     */
    public function testGhostsAreClassifiedDormant(): void
    {
        $profile = $this->spawn(1, 'ghost');

        $this->assertSame(BotLod::Dormant, resolve(BotLodClassifier::class)->classify($profile));
    }

    /**
     * A bot living next door to a human must stay fully simulated, because that is exactly the
     * account a human is most likely to look at, scout or attack.
     */
    public function testBotsNearHumansAreFullySimulated(): void
    {
        $profile = $this->spawn(1, 'miner');
        $botPlanet = Planet::where('user_id', $profile->user_id)->firstOrFail();

        // Move a human planet into the same system.
        $humanPlanet = Planet::query()
            ->whereNotIn('user_id', BotProfile::pluck('user_id'))
            ->whereNotNull('user_id')
            ->firstOrFail();

        $humanPlanet->galaxy = $botPlanet->galaxy;
        $humanPlanet->system = $botPlanet->system;
        $humanPlanet->save();

        Cache::forget('bots:human_systems');

        $this->assertSame(
            BotLod::Full,
            resolve(BotLodClassifier::class)->classify($profile),
            'A bot in a human\'s system must be fully simulated.'
        );
    }

    /**
     * Level of detail must change cadence only. A bot that has been simulated coarsely still has
     * a correct, current account the moment anyone looks at it.
     */
    public function testMaterialisingAStaleBotBringsItUpToDate(): void
    {
        $profile = $this->spawn(1, 'miner');

        $factory = resolve(PlayerServiceFactory::class);
        $planet = $factory->make($profile->user_id, true)->planets->current();
        $before = $planet->getResources()->metal->get();

        // The bot has not taken a turn in a long time, exactly as an Abstract bot would not.
        $profile->lod = BotLod::Abstract;
        $profile->last_tick_at = Date::now()->subDay();
        $profile->save();

        Date::setTestNow(Date::now()->addHours(6));

        $this->assertTrue(
            resolve(BotMaterialiser::class)->materialise($profile->user_id),
            'A stale bot must be materialised on demand.'
        );

        $after = $factory->make($profile->user_id, true)->planets->current()->getResources()->metal->get();

        $this->assertGreaterThan($before, $after, 'Materialising must catch the planet up on production.');
    }

    /**
     * Materialising is bounded, or a galaxy page load would pay for every bot in the system.
     */
    public function testMaterialisingIsRateLimited(): void
    {
        $profile = $this->spawn(1, 'miner');
        $profile->last_tick_at = Date::now()->subDay();
        $profile->save();

        $materialiser = resolve(BotMaterialiser::class);

        $this->assertTrue($materialiser->materialise($profile->user_id));
        $this->assertFalse(
            $materialiser->materialise($profile->user_id),
            'A bot just materialised must not be synchronised again immediately.'
        );
    }

    /**
     * A bot that has just ticked is already current, so looking at it costs nothing.
     */
    public function testFreshBotsAreNotMaterialisedAgain(): void
    {
        $profile = $this->spawn(1, 'miner');
        $profile->last_tick_at = Date::now();
        $profile->save();

        $planet = Planet::where('user_id', $profile->user_id)->firstOrFail();

        // A no-op: nothing in this system is stale, so no bot is synchronised.
        resolve(BotMaterialiser::class)->materialiseSystem($planet->galaxy, $planet->system);

        // If it had been synchronised, the cooldown marker would exist.
        $this->assertFalse(
            Cache::has('bots:materialised:' . $profile->user_id),
            'A bot that ticked moments ago must not be synchronised again on a page view.'
        );
    }

    /**
     * The pause switch stops turns without touching config or losing any bots.
     */
    public function testPauseStopsTicksAndResumeRestartsThem(): void
    {
        $profile = $this->spawn(1, 'miner');
        $profile->activity_profile = array_merge($profile->activity_profile, ['awake_hours' => [0, 24]]);
        $profile->next_action_at = Date::now()->subMinute();
        $profile->save();

        $this->assertSame(0, Artisan::call('ogamex:bots:pause'));
        $this->assertSame(0, Artisan::call('ogamex:bots:tick', ['--sync' => true]));

        $this->assertSame(
            0,
            BotActionLog::where('bot_user_id', $profile->user_id)->count(),
            'A paused server must not give bots turns.'
        );

        // Bots are still there, just idle.
        $this->assertSame(1, BotProfile::count());

        $this->assertSame(0, Artisan::call('ogamex:bots:pause', ['--resume' => true]));

        BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        $this->assertSame(0, Artisan::call('ogamex:bots:tick', ['--sync' => true]));

        $this->assertGreaterThan(
            0,
            BotActionLog::where('bot_user_id', $profile->user_id)->count(),
            'Resuming must let bots act again.'
        );
    }

    /**
     * The sweep records what it did, so an operator can see the population outgrowing the worker
     * before it becomes a backlog.
     */
    public function testSweepRecordsMetrics(): void
    {
        $this->spawn(2, 'miner');
        BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);

        Artisan::call('ogamex:bots:tick');

        $sweep = Cache::get('bots:last_sweep');

        $this->assertIsArray($sweep);
        $this->assertArrayHasKey('dispatched', $sweep);
        $this->assertArrayHasKey('elapsed_ms', $sweep);
        $this->assertArrayHasKey('backlog', $sweep);
    }
}
