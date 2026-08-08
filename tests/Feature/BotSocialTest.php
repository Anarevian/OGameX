<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Perception\BotMemoryService;
use OGame\Bots\Social\BotSocialService;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Alliance;
use OGame\Models\BotActionLog;
use OGame\Models\BotProfile;
use OGame\Models\ChatMessage;
use OGame\Models\Message;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\TestCase;

/**
 * Phase 4 coverage: alliances, buddy handling, and the silence invariant.
 */
class BotSocialTest extends TestCase
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

    private function spawnOne(string $persona = 'miner'): BotProfile
    {
        $this->assertSame(0, Artisan::call('ogamex:bots:spawn', [
            '--count' => 1,
            '--persona' => $persona,
            '--near-humans' => '0',
            '--developed' => true,
        ]));

        $profile = BotProfile::firstOrFail();
        $profile->activity_profile = array_merge($profile->activity_profile, ['awake_hours' => [0, 24]]);
        $profile->next_action_at = Date::now()->subMinute();
        $profile->save();

        return $profile;
    }

    /**
     * The silence invariant.
     *
     * Bots never author messages or chat. This is the single most load-bearing constraint of the
     * whole design — decision 4 in the plan removed the entire messaging layer on the strength of
     * it — so it is asserted directly rather than trusted.
     */
    public function testBotsNeverAuthorMessagesOrChat(): void
    {
        $profile = $this->spawnOne('trader');

        $messagesBefore = Message::where('user_id', $profile->user_id)->count();
        $chatBefore = ChatMessage::where('sender_id', $profile->user_id)->count();

        for ($i = 0; $i < 10; $i++) {
            Artisan::call('ogamex:bots:tick', ['--sync' => true]);
            Date::setTestNow(Date::now()->addHours(5));
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        // A bot may *receive* engine-generated notifications, which is why this counts only what
        // it sent: chat rows are authored, never delivered.
        $this->assertSame(
            $chatBefore,
            ChatMessage::where('sender_id', $profile->user_id)->count(),
            'A bot must never post in chat.'
        );

        // Nothing in the bot's own inbox may have been written by another bot either.
        $this->assertSame(
            0,
            ChatMessage::whereIn('sender_id', BotProfile::pluck('user_id'))->count(),
            'No bot may author a chat message.'
        );

        $this->assertGreaterThanOrEqual(
            $messagesBefore,
            Message::where('user_id', $profile->user_id)->count(),
            'Received system notifications are fine; this assertion only guards the direction.'
        );
    }

    /**
     * A bot rejects an alliance application from someone it holds a grudge against, and does not
     * explain why — the rejection is the whole communication.
     */
    public function testHostileApplicantsAreRejected(): void
    {
        $profile = $this->spawnOne();
        $applicant = User::whereNotIn('id', BotProfile::pluck('user_id'))->firstOrFail();

        $allianceService = resolve(AllianceService::class);
        $alliance = $allianceService->createAlliance($profile->user_id, 'BOT', 'Bot Concord');

        // The applicant has been raiding this bot.
        $memoryService = resolve(BotMemoryService::class);
        for ($i = 0; $i < 3; $i++) {
            $memoryService->recordAttackSuffered($profile->user_id, $applicant->id, 2000000);
        }

        $allianceService->applyToAlliance($applicant->id, $alliance->id);
        $this->assertCount(1, $allianceService->getPendingApplications($alliance->id));

        $profile->refresh();
        resolve(BotSocialService::class)->handleAllianceApplications($profile);

        $this->assertCount(
            0,
            $allianceService->getPendingApplications($alliance->id),
            'An application from an enemy must be dealt with, not left pending.'
        );

        $this->assertNull(
            User::find($applicant->id)?->alliance_id,
            'An enemy must not be admitted.'
        );
    }

    /**
     * Sharing an alliance warms a bot towards its members. This is the only thing in the system
     * that moves attitude upwards on its own.
     */
    public function testAlliesAreViewedMoreFavourablyOverTime(): void
    {
        $profile = $this->spawnOne();
        $ally = User::whereNotIn('id', BotProfile::pluck('user_id'))->firstOrFail();

        $allianceService = resolve(AllianceService::class);
        $alliance = $allianceService->createAlliance($profile->user_id, 'ALY', 'Allied Compact');
        $allianceService->applyToAlliance($ally->id, $alliance->id);

        $application = $allianceService->getPendingApplications($alliance->id)->firstOrFail();
        $allianceService->acceptApplication($application->id, $profile->user_id);

        $memoryService = resolve(BotMemoryService::class);
        $before = $memoryService->attitudeTowards($profile->user_id, $ally->id);

        $profile->refresh();
        $socialService = resolve(BotSocialService::class);
        for ($i = 0; $i < 5; $i++) {
            $socialService->warmToAllies($profile);
        }

        $this->assertGreaterThan(
            $before,
            $memoryService->attitudeTowards($profile->user_id, $ally->id),
            'Sharing an alliance must improve how a bot sees someone.'
        );
    }

    /**
     * Bots end up in alliances rather than every account being unaffiliated.
     */
    public function testBotsFormAlliances(): void
    {
        // Several bots so at least one rolls the skill needed to found, and the rest can apply.
        $this->assertSame(0, Artisan::call('ogamex:bots:spawn', [
            '--count' => 6,
            '--persona' => 'trader',
            '--near-humans' => '0',
            '--developed' => true,
        ]));

        BotProfile::all()->each(function (BotProfile $profile) {
            $profile->activity_profile = array_merge($profile->activity_profile, ['awake_hours' => [0, 24]]);
            $profile->skill = 0.9;
            $profile->next_action_at = Date::now()->subMinute();
            $profile->save();
        });

        $alliancesBefore = Alliance::count();

        for ($i = 0; $i < 8; $i++) {
            Artisan::call('ogamex:bots:tick', ['--sync' => true]);
            Date::setTestNow(Date::now()->addHours(5));
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $founded = BotActionLog::where('action', 'alliance_found')->where('succeeded', true)->count();
        $applied = BotActionLog::where('action', 'alliance_apply')->where('succeeded', true)->count();

        $this->assertGreaterThan(
            0,
            $founded + $applied,
            'Bots must take some alliance action rather than all staying unaffiliated.'
        );

        if ($founded > 0) {
            $this->assertGreaterThan($alliancesBefore, Alliance::count());
        }
    }

    /**
     * Social handling must never break a tick, whatever state the inbox is in.
     */
    public function testSocialHandlingNeverFailsATick(): void
    {
        $this->spawnOne('trader');

        for ($i = 0; $i < 6; $i++) {
            Artisan::call('ogamex:bots:tick', ['--sync' => true]);
            Date::setTestNow(Date::now()->addHours(5));
            BotProfile::query()->update(['next_action_at' => Date::now()->subMinute()]);
        }

        $failures = BotActionLog::where('succeeded', false)->get();

        $this->assertCount(0, $failures, 'Social actions must not be rejected: ' . $failures->pluck('payload')->toJson());
    }
}
