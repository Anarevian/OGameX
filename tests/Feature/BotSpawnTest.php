<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Support\BotProgression;
use OGame\Bots\Support\BotSynchroniser;
use OGame\Enums\BotLod;
use OGame\Enums\BotPersona;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BotProfile;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Models\UserTech;
use OGame\Services\ObjectService;
use Tests\TestCase;

/**
 * Phase 0 coverage for the NPC (bot) subsystem: spawning, marking, synchronising and despawning.
 */
class BotSpawnTest extends TestCase
{
    /**
     * The suite does not roll the database back between tests, so any bots left behind by a
     * previous test would break the exact counts asserted here. Start every test from zero.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->removeAllBots();
    }

    /**
     * Leave no bot accounts behind for the rest of the suite.
     */
    protected function tearDown(): void
    {
        $this->removeAllBots();

        parent::tearDown();
    }

    /**
     * Delete every bot account currently in the database.
     */
    private function removeAllBots(): void
    {
        $playerServiceFactory = resolve(PlayerServiceFactory::class);

        foreach (BotProfile::pluck('user_id') as $userId) {
            $playerServiceFactory->make((int) $userId, true)->delete();
        }
    }

    /**
     * Spawning creates a complete, playable account for every bot.
     */
    public function testSpawnCreatesCompleteAccounts(): void
    {
        $this->assertArtisanSucceeds('ogamex:bots:spawn', ['--count' => 6, '--near-humans' => '0']);

        $profiles = BotProfile::all();
        $this->assertCount(6, $profiles);

        foreach ($profiles as $profile) {
            $user = User::find($profile->user_id);
            $this->assertNotNull($user, 'Bot profile must point at a real user.');

            // Every account needs a tech row and at least a homeworld, or the game breaks when
            // a human loads the galaxy view on it.
            $this->assertDatabaseHas('users_tech', ['user_id' => $user->id]);
            $this->assertGreaterThanOrEqual(1, Planet::where('user_id', $user->id)->count());
            $this->assertNotNull($user->planet_current);

            // Bot accounts must be identifiable, and must not be reachable by e-mail.
            $this->assertTrue($user->isBot());
            $this->assertStringEndsWith('@' . (string) config('bots.email_domain'), $user->email);
        }
    }

    /**
     * Each bot gets its own synthetic IP, so the admin panel's shared-IP grouping does not see
     * the whole population as one cluster of linked accounts.
     */
    public function testBotsDoNotShareAnIpAddress(): void
    {
        $this->assertArtisanSucceeds('ogamex:bots:spawn', ['--count' => 8, '--near-humans' => '0']);

        $userIds = BotProfile::pluck('user_id');
        $ips = User::whereIn('id', $userIds)->pluck('last_ip');

        $this->assertCount(8, $ips);
        $this->assertCount(8, $ips->unique(), 'Every bot must have a distinct synthetic IP.');
    }

    /**
     * Spawned accounts must never hold a building or technology they could not have built.
     */
    public function testSpawnedProgressionSatisfiesRequirements(): void
    {
        $this->assertArtisanSucceeds('ogamex:bots:spawn', ['--count' => 5, '--near-humans' => '0']);

        foreach (BotProfile::all() as $profile) {
            $tech = UserTech::where('user_id', $profile->user_id)->first();
            $this->assertNotNull($tech);

            $planet = Planet::where('user_id', $profile->user_id)->first();
            $this->assertNotNull($planet);

            // A research lab is the prerequisite for every technology, so any account holding
            // research must have one. This catches the most common way a hand-seeded account
            // ends up in a state the game itself could never produce.
            $hasResearch = false;
            foreach (ObjectService::getResearchObjects() as $research) {
                if ((int) $tech->getAttribute($research->machine_name) > 0) {
                    $hasResearch = true;
                    break;
                }
            }

            if ($hasResearch) {
                $this->assertGreaterThanOrEqual(
                    1,
                    $planet->research_lab,
                    'A bot with researched technology must own a research lab.'
                );
            }
        }
    }

    /**
     * Ghost accounts stopped playing and must be allowed to drift into the inactive markers,
     * while active personas must not.
     */
    public function testGhostsLookInactiveAndAreNotTickable(): void
    {
        $this->assertArtisanSucceeds('ogamex:bots:spawn', ['--count' => 4, '--persona' => 'ghost', '--near-humans' => '0']);

        foreach (BotProfile::all() as $profile) {
            $this->assertFalse($profile->isTickable(), 'Ghosts must never be ticked.');
            $this->assertFalse($profile->isAwake());
            $this->assertSame(BotLod::Dormant, $profile->lod);
            $this->assertNull($profile->next_action_at);
        }
    }

    /**
     * Active bots are scheduled to act, and are spread out rather than all waking in one minute.
     */
    public function testActiveBotsAreScheduled(): void
    {
        $this->assertArtisanSucceeds('ogamex:bots:spawn', ['--count' => 6, '--persona' => 'miner', '--near-humans' => '0']);

        $profiles = BotProfile::all();

        foreach ($profiles as $profile) {
            $this->assertTrue($profile->isTickable());

            $nextAction = $profile->next_action_at;
            $this->assertNotNull($nextAction);
            $this->assertTrue($nextAction->greaterThan(Date::now()->subMinute()));
        }

        // With six bots scheduled over an hour, they should not all land on the same minute.
        $this->assertGreaterThan(
            1,
            $profiles->pluck('next_action_at')->map(fn ($t) => $t?->format('Y-m-d H:i'))->unique()->count(),
            'Bot wake-up times must be spread out.'
        );
    }

    /**
     * The synchroniser advances a bot's state outside of an HTTP request, which is what makes
     * bots playable at all given the game has no global tick.
     */
    public function testSynchroniserAdvancesResourcesWithoutHttpContext(): void
    {
        $this->assertArtisanSucceeds('ogamex:bots:spawn', ['--count' => 1, '--persona' => 'miner', '--near-humans' => '0']);

        $profile = BotProfile::firstOrFail();
        $playerServiceFactory = resolve(PlayerServiceFactory::class);
        $player = $playerServiceFactory->make($profile->user_id, true);

        $planet = $player->planets->current();
        $before = $planet->getResources()->metal->get();

        // Advance the clock so production has something to produce.
        Date::setTestNow(Date::now()->addHours(3));

        resolve(BotSynchroniser::class)->sync($player, $profile);

        $after = $playerServiceFactory->make($profile->user_id, true)->planets->current()->getResources()->metal->get();

        Date::setTestNow();

        $this->assertGreaterThan($before, $after, 'A synchronised bot planet must accrue production.');
    }

    /**
     * A sleeping bot must not have its activity clock stamped, or every bot would permanently
     * read as online in the galaxy view.
     */
    public function testSynchroniserDoesNotStampActivityWhileAsleep(): void
    {
        $this->assertArtisanSucceeds('ogamex:bots:spawn', ['--count' => 1, '--persona' => 'miner', '--near-humans' => '0']);

        $profile = BotProfile::firstOrFail();

        // Force a window the bot cannot currently be inside.
        $profile->activity_profile = array_merge($profile->activity_profile, ['awake_hours' => [0, 0]]);
        $profile->save();
        $this->assertFalse($profile->isAwake());

        $player = resolve(PlayerServiceFactory::class)->make($profile->user_id, true);
        $timeBefore = $player->getUser()->time;

        Date::setTestNow(Date::now()->addHours(2));
        resolve(BotSynchroniser::class)->sync($player, $profile);
        Date::setTestNow();

        $this->assertSame(
            $timeBefore,
            User::find($profile->user_id)?->time,
            'A sleeping bot must not refresh its last-activity timestamp.'
        );
    }

    /**
     * Progression must scale with account age, so the population does not look like it appeared
     * all at once.
     */
    public function testProgressionScalesWithAccountAge(): void
    {
        $progression = resolve(BotProgression::class);

        $young = $progression->planetConfig(BotPersona::Miner, 2, 0.7);
        $old = $progression->planetConfig(BotPersona::Miner, 180, 0.7);

        $this->assertGreaterThan(
            $young['metal_mine'],
            $old['metal_mine'],
            'An older account must be further developed than a young one.'
        );
    }

    /**
     * Despawning removes the accounts and everything hanging off them.
     */
    public function testDespawnRemovesEverything(): void
    {
        $this->assertArtisanSucceeds('ogamex:bots:spawn', ['--count' => 3, '--near-humans' => '0']);

        $userIds = BotProfile::pluck('user_id')->all();
        $this->assertCount(3, $userIds);

        $this->assertArtisanSucceeds('ogamex:bots:despawn', ['--all' => true, '--force' => true]);

        $this->assertSame(0, BotProfile::count());
        $this->assertSame(0, User::whereIn('id', $userIds)->count());
        $this->assertSame(0, Planet::whereIn('user_id', $userIds)->count());
        $this->assertSame(0, UserTech::whereIn('user_id', $userIds)->count());
    }

    /**
     * Despawn refuses to wipe the population without an explicit filter or --all.
     */
    public function testDespawnRequiresAnExplicitFilter(): void
    {
        $this->assertArtisanSucceeds('ogamex:bots:spawn', ['--count' => 2, '--near-humans' => '0']);

        $this->assertArtisanFails('ogamex:bots:despawn', ['--force' => true]);

        $this->assertSame(2, BotProfile::count());
    }

    /**
     * Only the named persona is removed when --persona is given.
     */
    public function testDespawnCanTargetASinglePersona(): void
    {
        $this->assertArtisanSucceeds('ogamex:bots:spawn', ['--count' => 2, '--persona' => 'turtle', '--near-humans' => '0']);
        $this->assertArtisanSucceeds('ogamex:bots:spawn', ['--count' => 2, '--persona' => 'miner', '--near-humans' => '0']);

        $this->assertArtisanSucceeds('ogamex:bots:despawn', ['--persona' => 'turtle', '--force' => true]);

        $this->assertSame(0, BotProfile::where('persona', 'turtle')->count());
        $this->assertSame(2, BotProfile::where('persona', 'miner')->count());
    }

    /**
     * An unknown persona is rejected rather than silently spawning the default mix.
     */
    public function testSpawnRejectsUnknownPersona(): void
    {
        $this->assertArtisanFails('ogamex:bots:spawn', ['--count' => 1, '--persona' => 'not-a-persona']);

        $this->assertSame(0, BotProfile::count());
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
     * Run an artisan command and assert it failed.
     *
     * @param array<string, mixed> $parameters
     */
    private function assertArtisanFails(string $command, array $parameters = []): void
    {
        $this->assertNotSame(0, Artisan::call($command, $parameters), $command . ' should fail.');
    }
}
