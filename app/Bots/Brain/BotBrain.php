<?php

namespace OGame\Bots\Brain;

use Illuminate\Support\Str;
use OGame\Bots\Actions\BotAction;
use OGame\Bots\Actions\BuildBuildingAction;
use OGame\Bots\Actions\BuildUnitsAction;
use OGame\Bots\Actions\ResearchAction;
use OGame\Models\BotActionLog;
use OGame\Models\BotProfile;
use OGame\Services\PlayerService;
use Throwable;

/**
 * Decides what a bot does when it sits down to play.
 *
 * The model is utility scoring rather than a scripted build order. Every action class proposes
 * whatever it could legally and affordably do right now; each candidate is scored on its own
 * merits, then adjusted by the bot's stance, its persona focus and its skill-scaled noise; the
 * best few are executed, limited by an action budget.
 *
 * Two properties fall out of this for free, and both matter more than clever play:
 *  - Two bots of different personas diverge visibly over a week without anyone scripting them.
 *  - A low-skill bot makes recognisably worse choices than a high-skill one, because the noise
 *    term is scaled by skill rather than being uniform.
 */
class BotBrain
{
    /**
     * @param array<int, BotAction> $actions
     */
    private array $actions;

    public function __construct(
        BuildBuildingAction $buildBuildingAction,
        ResearchAction $researchAction,
        BuildUnitsAction $buildUnitsAction,
    ) {
        // Phase 1 is the economy. Fleet, espionage and raiding actions join this list in Phase 2.
        $this->actions = [
            $buildBuildingAction,
            $researchAction,
            $buildUnitsAction,
        ];
    }

    /**
     * Run one session's worth of decisions for a bot.
     *
     * @param PlayerService $player
     * @param BotProfile $profile
     * @param int $budget How many actions this session may take.
     * @return int How many actions were actually executed.
     */
    public function run(PlayerService $player, BotProfile $profile, int $budget): int
    {
        if ($budget < 1) {
            return 0;
        }

        $tickId = (string) Str::uuid();
        $stance = $this->determineStance($player, $profile);
        $this->rememberStance($profile, $stance);

        $executed = 0;

        // Re-propose after every action rather than picking a batch up front: executing one
        // candidate spends resources and changes what is possible, so a batch chosen in advance
        // would mostly consist of things the bot can no longer afford.
        for ($step = 0; $step < $budget; $step++) {
            $context = new BotContext($player, $profile, $stance, $tickId);

            $best = $this->bestCandidate($context);
            if ($best === null) {
                break;
            }

            if ($this->execute($context, $best)) {
                $executed++;
            }
        }

        return $executed;
    }

    /**
     * Gather every candidate, score it and return the winner.
     */
    private function bestCandidate(BotContext $context): ActionCandidate|null
    {
        $best = null;

        foreach ($this->actions as $action) {
            try {
                $candidates = $action->propose($context);
            } catch (Throwable) {
                // A broken proposal must never take the whole tick down with it.
                continue;
            }

            foreach ($candidates as $candidate) {
                $candidate->score = $context->withNoise(
                    $candidate->score * $context->stance->modifierFor($candidate->category)
                );

                if ($best === null || $candidate->score > $best->score) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /**
     * Execute a candidate and record what happened.
     *
     * A failure here is information rather than a bug: it almost always means the bot
     * mis-estimated something, which is behaviour worth keeping and worth being able to see.
     */
    private function execute(BotContext $context, ActionCandidate $candidate): bool
    {
        $succeeded = true;
        $payload = $candidate->payload;

        try {
            ($candidate->execute)();
        } catch (Throwable $e) {
            $succeeded = false;
            $payload['error'] = $e->getMessage();
        }

        BotActionLog::create([
            'bot_user_id' => $context->player->getId(),
            'tick_id' => $context->tickId,
            'action' => $candidate->action,
            'succeeded' => $succeeded,
            'score' => round($candidate->score, 4),
            'reason' => Str::limit($candidate->reason, 250, ''),
            'payload' => $payload,
        ]);

        return $succeeded;
    }

    /**
     * Work out the bot's current posture.
     *
     * Phase 1 covers the economic stances only. The triggers are deliberately coarse: a stance
     * is meant to describe what a bot is broadly doing over hours, not to react to every change.
     */
    private function determineStance(PlayerService $player, BotProfile $profile): BotStance
    {
        $config = $profile->persona->config();
        /** @var array<string, mixed> $focus */
        $focus = is_array($config['focus'] ?? null) ? $config['focus'] : [];

        $economy = (float) ($focus['economy'] ?? 0.5);
        $research = (float) ($focus['research'] ?? 0.5);
        $military = max((float) ($focus['fleet'] ?? 0.5), (float) ($focus['defence'] ?? 0.5));

        // A bot with nothing researching and a real interest in research goes after it.
        if (!$player->isResearching() && $research >= $economy && $research >= $military) {
            return BotStance::Researching;
        }

        if ($military > $economy && $military > $research) {
            return BotStance::Militarising;
        }

        return BotStance::Economising;
    }

    /**
     * Persist the current stance so it is visible in the admin panel and in tests.
     */
    private function rememberStance(BotProfile $profile, BotStance $stance): void
    {
        $state = $profile->state ?? [];
        $state['stance'] = $stance->value;
        $profile->state = $state;
    }
}
