<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Activity\ActivityScheduler;
use OGame\Enums\BotLod;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BotActionLog;
use OGame\Models\BotProfile;
use Tests\TestCase;

/**
 * Guards the early game.
 *
 * A fresh bot has to climb out of an empty homeworld using the same services a human does. Three
 * separate defects each stopped that happening while every other test still passed, because none
 * of them broke a tick — they just made the bot spend its turns badly. These assert the outcome
 * rather than the mechanism, which is the only way that class of bug shows up.
 */
class BotGrowthTest extends TestCase
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

    private function spawnFresh(string $persona = 'miner'): BotProfile
    {
        $this->assertSame(0, Artisan::call('ogamex:bots:spawn', [
            '--count' => 1,
            '--persona' => $persona,
            '--near-humans' => '0',
            '--fresh' => true,
        ]));

        $profile = BotProfile::firstOrFail();
        $profile->activity_profile = array_merge($profile->activity_profile, ['awake_hours' => [0, 24]]);
        $profile->next_action_at = Date::now()->subMinute();
        $profile->save();

        return $profile;
    }

    /**
     * A session must never put the same building into the queue twice.
     *
     * Scores are derived from getObjectLevel(), which reports what is built and knows nothing
     * about what is queued. The brain re-proposes after every action, so a mine that stayed at
     * its pre-queue level kept its pre-queue payback and won every remaining slot — five copies
     * of "metal mine 2" in one session, and no solar plant, lab or shipyard ever.
     */
    public function testASessionNeverQueuesTheSameBuildingTwice(): void
    {
        $profile = $this->spawnFresh();

        Artisan::call('ogamex:bots:tick', ['--sync' => true]);

        $byTick = BotActionLog::where('bot_user_id', $profile->user_id)
            ->where('action', 'build_building')
            ->get()
            ->groupBy('tick_id');

        $this->assertNotEmpty($byTick, 'A fresh bot must build something on its first turn.');

        foreach ($byTick as $tickId => $entries) {
            $buildings = $entries->pluck('payload.building')->all();

            $this->assertSame(
                count($buildings),
                count(array_unique($buildings)),
                sprintf('Tick %s queued the same building more than once: %s', $tickId, implode(', ', $buildings)),
            );
        }
    }

    /**
     * A young account keeps full cadence even when it is nowhere near a human.
     *
     * The abstract multiplier exists to avoid re-simulating settled empires. Applied to an account
     * that owns nothing it is not a saving: the building queue holds five items, so a longer
     * session cannot make up for a six-fold longer gap, and the bot never develops.
     */
    public function testYoungBotsAreNotSlowedByAbstractDetail(): void
    {
        $profile = $this->spawnFresh();
        $profile->lod = BotLod::Abstract;
        $profile->save();

        $scheduler = resolve(ActivityScheduler::class);

        $youngGap = $this->shortestGapOverRolls($scheduler, $profile);

        // Age the account past the grace window; nothing else about it changes.
        $user = $profile->user;
        $user->created_at = Date::now()->subDays((int) config('bots.tick.full_cadence_days', 14) + 30);
        $user->save();
        $profile->refresh();

        $establishedGap = $this->shortestGapOverRolls($scheduler, $profile);

        $this->assertGreaterThan(
            $youngGap,
            $establishedGap,
            'An established distant bot must be scheduled less often than a brand-new one.'
        );
    }

    /**
     * Smallest gap seen across several rolls, in minutes.
     *
     * The gap is deliberately randomised, so a single roll proves nothing; the floor separates
     * the two regimes cleanly.
     */
    private function shortestGapOverRolls(ActivityScheduler $scheduler, BotProfile $profile): float
    {
        $shortest = null;

        for ($i = 0; $i < 12; $i++) {
            $next = $scheduler->nextSessionStart($profile);
            $this->assertNotNull($next);

            $minutes = Date::now()->diffInMinutes($next, true);
            $shortest = $shortest === null ? $minutes : min($shortest, $minutes);
        }

        return (float) $shortest;
    }

    /**
     * The whole point, asserted end to end: a fresh bot builds an actual economy.
     *
     * Deliberately outcome-based and deliberately generous. It is not checking that the bot plays
     * well, only that it is playing at all — every regression here produced an account that ticked
     * happily for a simulated week and still owned almost nothing.
     */
    public function testAFreshBotDevelopsAnEconomy(): void
    {
        $profile = $this->spawnFresh();

        // Four simulated days at four-hour steps. Long enough for the early build order to show,
        // short enough to keep the suite usable.
        for ($step = 0; $step < 24; $step++) {
            Artisan::call('ogamex:bots:tick', ['--sync' => true]);
            Date::setTestNow(Date::now()->addHours(4));
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $player = resolve(PlayerServiceFactory::class)->make($profile->user_id, true);
        $home = $player->planets->first();
        $this->assertNotNull($home);

        $this->assertGreaterThanOrEqual(
            6,
            $home->getObjectLevel('metal_mine'),
            'Four simulated days should leave a bot well past its starting mine.'
        );

        $this->assertGreaterThan(
            0,
            $home->getObjectLevel('solar_plant'),
            'A bot that never builds energy runs every mine at reduced output for ever.'
        );

        // The gateway buildings are what a bot is permanently locked out of if mines monopolise
        // the queue, and losing the lab means losing the entire research tree.
        $this->assertGreaterThan(
            0,
            $home->getObjectLevel('research_lab'),
            'Without a research lab the bot can never research anything at all.'
        );

        $this->assertGreaterThan(
            0,
            BotActionLog::where('bot_user_id', $profile->user_id)->where('action', 'research')->count(),
            'A bot with a lab must actually use it.'
        );
    }
}
