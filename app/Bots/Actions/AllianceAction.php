<?php

namespace OGame\Bots\Actions;

use OGame\Bots\Brain\ActionCandidate;
use OGame\Bots\Brain\BotContext;
use OGame\Bots\Perception\BotMemoryService;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\BotMemory;
use OGame\Services\AllianceService;

/**
 * Founds an alliance, or applies to join one.
 *
 * Alliances are the one piece of social structure bots have, given they never speak. A universe
 * where every account is unaffiliated looks wrong in the galaxy view and the highscores, where a
 * real server is mostly tags.
 *
 * A bot applies to an alliance it feels positively about and founds its own when there is nothing
 * suitable nearby, which is roughly how alliances actually come about.
 */
class AllianceAction implements BotAction
{
    /**
     * Words combined into alliance names and tags.
     *
     * @var array<int, string>
     */
    private const NAME_FIRST = [
        'Iron', 'Void', 'Solar', 'Crimson', 'Silent', 'Outer', 'Free', 'Dark', 'Nova', 'Astral',
        'Orion', 'Titan', 'Eclipse', 'Vanguard', 'Northern', 'Imperial', 'Rogue', 'Quantum',
    ];

    /**
     * @var array<int, string>
     */
    private const NAME_SECOND = [
        'Legion', 'Concord', 'Union', 'Syndicate', 'Compact', 'Order', 'Fleet', 'Covenant',
        'Federation', 'Directorate', 'Alliance', 'Collective', 'Guild', 'Coalition',
    ];

    public function __construct(
        private readonly AllianceService $allianceService,
        private readonly BotMemoryService $memoryService,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function propose(BotContext $context): array
    {
        $user = $context->player->getUser();

        // Already affiliated, or in the cooldown after leaving one.
        if ($user->alliance_id !== null) {
            return [];
        }

        // An application already pending: a player does not spam applications while waiting.
        if (AllianceApplication::where('user_id', $context->player->getId())->where('status', 0)->exists()) {
            return [];
        }

        $candidate = $this->proposeApplication($context) ?? $this->proposeFounding($context);

        return $candidate === null ? [] : [$candidate];
    }

    /**
     * Apply to an existing alliance the bot does not dislike.
     */
    private function proposeApplication(BotContext $context): ActionCandidate|null
    {
        $botUserId = $context->player->getId();

        // Only alliances that already contain someone: an empty tag is not worth joining.
        $alliances = Alliance::query()->where('is_open', true)->inRandomOrder()->limit(10)->get();

        foreach ($alliances as $alliance) {
            $leaderId = (int) $alliance->founder_user_id;

            if ($leaderId === $botUserId) {
                continue;
            }

            // Never apply to an alliance run by someone the bot has a grudge against.
            if ($this->memoryService->attitudeTowards($botUserId, $leaderId) <= BotMemory::HOSTILE_THRESHOLD) {
                continue;
            }

            return new ActionCandidate(
                action: 'alliance_apply',
                category: 'social',
                score: 1.2,
                reason: sprintf('apply to [%s]', $alliance->alliance_tag),
                payload: ['alliance_id' => $alliance->id, 'tag' => $alliance->alliance_tag],
                // No application message: bots are silent, so the application goes in bare.
                execute: function () use ($botUserId, $alliance) {
                    $this->allianceService->applyToAlliance($botUserId, $alliance->id);
                },
            );
        }

        return null;
    }

    /**
     * Found a new alliance when there is nothing worth joining.
     *
     * Only bots that would plausibly run one bother: it takes some standing in the universe, and
     * a brand new account starting an alliance on day one would look odd.
     */
    private function proposeFounding(BotContext $context): ActionCandidate|null
    {
        // Founders are the more capable, more sociable accounts.
        if ($context->profile->skill < 0.6) {
            return null;
        }

        // Something to offer: an account with no colonies is not leading anything.
        if (count($context->planets()) < 2) {
            return null;
        }

        $botUserId = $context->player->getId();

        return new ActionCandidate(
            action: 'alliance_found',
            category: 'social',
            score: 0.9,
            reason: 'found a new alliance',
            payload: [],
            execute: function () use ($botUserId) {
                [$tag, $name] = $this->rollIdentity();

                $this->allianceService->createAlliance($botUserId, $tag, $name);
            },
        );
    }

    /**
     * Pick an alliance tag and name that are not already taken.
     *
     * @return array{string, string}
     */
    private function rollIdentity(): array
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $first = self::NAME_FIRST[random_int(0, count(self::NAME_FIRST) - 1)];
            $second = self::NAME_SECOND[random_int(0, count(self::NAME_SECOND) - 1)];

            $name = $first . ' ' . $second;
            // Tags are 3-8 characters.
            $tag = strtoupper(substr($first, 0, 3) . substr($second, 0, 2));

            if (!Alliance::where('alliance_tag', $tag)->exists() && !Alliance::where('alliance_name', $name)->exists()) {
                return [$tag, $name];
            }
        }

        // Fall back to something certainly unique rather than failing the action.
        $suffix = random_int(100, 999);

        return ['NX' . $suffix, 'Nexus ' . $suffix];
    }
}
