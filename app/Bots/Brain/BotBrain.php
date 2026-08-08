<?php

namespace OGame\Bots\Brain;

use Illuminate\Support\Str;
use OGame\Bots\Actions\AllianceAction;
use OGame\Bots\Actions\BotAction;
use OGame\Bots\Actions\BuildBuildingAction;
use OGame\Bots\Actions\BuildUnitsAction;
use OGame\Bots\Actions\ColoniseAction;
use OGame\Bots\Actions\EspionageAction;
use OGame\Bots\Actions\ExpeditionAction;
use OGame\Bots\Actions\FleetsaveAction;
use OGame\Bots\Actions\RaidAction;
use OGame\Bots\Actions\RecycleAction;
use OGame\Bots\Actions\ResearchAction;
use OGame\Bots\Actions\TransportAction;
use OGame\Bots\Support\BotActivityLog;
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
        ExpeditionAction $expeditionAction,
        EspionageAction $espionageAction,
        RaidAction $raidAction,
        FleetsaveAction $fleetsaveAction,
        TransportAction $transportAction,
        ColoniseAction $coloniseAction,
        RecycleAction $recycleAction,
        AllianceAction $allianceAction,
        private readonly BotActivityLog $activityLog,
    ) {
        $this->actions = [
            $buildBuildingAction,
            $researchAction,
            $buildUnitsAction,
            $expeditionAction,
            $espionageAction,
            $raidAction,
            $fleetsaveAction,
            $transportAction,
            $coloniseAction,
            $recycleAction,
            $allianceAction,
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

        // How many actions this session has already spent on each category.
        $spentPerCategory = [];

        // Re-propose after every action rather than picking a batch up front: executing one
        // candidate spends resources and changes what is possible, so a batch chosen in advance
        // would mostly consist of things the bot can no longer afford.
        for ($step = 0; $step < $budget; $step++) {
            $context = new BotContext($player, $profile, $stance, $tickId);

            $best = $this->bestCandidate($context, $spentPerCategory);
            if ($best === null) {
                break;
            }

            $spentPerCategory[$best->category] = ($spentPerCategory[$best->category] ?? 0) + 1;

            if ($this->execute($context, $best)) {
                $executed++;
            }
        }

        return $executed;
    }

    /**
     * How sharply a category's score drops after each action already spent on it this session.
     *
     * Without this the brain is not really a ranking: it takes the argmax every step, and one
     * category — buildings, because a multi-planet empire always has another cheap upgrade
     * available — wins every slot forever. A bot that spends all ten of its actions clicking the
     * same button is both worse at the game and obviously not a person. Real players do a couple
     * of different things per login.
     */
    private const CATEGORY_FATIGUE = 0.6;

    /**
     * Gather every candidate, score it and return the winner.
     *
     * @param array<string, int> $spentPerCategory Actions already taken this session, by category.
     */
    private function bestCandidate(BotContext $context, array $spentPerCategory = []): ActionCandidate|null
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
                $alreadySpent = $spentPerCategory[$candidate->category] ?? 0;
                $fatigue = 1 / (1 + (self::CATEGORY_FATIGUE * $alreadySpent));

                $candidate->score = $context->withNoise(
                    $candidate->score
                    * $context->stance->modifierFor($candidate->category)
                    * $fatigue
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

        $entry = BotActionLog::create([
            'bot_user_id' => $context->player->getId(),
            'tick_id' => $context->tickId,
            'action' => $candidate->action,
            'succeeded' => $succeeded,
            'score' => round($candidate->score, 4),
            'reason' => Str::limit($candidate->reason, 250, ''),
            'payload' => $payload,
        ]);

        // The table is the record; the file is what an operator can watch. Mirroring here rather
        // than in the model keeps it to decisions actually taken, not every row ever written.
        $this->activityLog->record(
            $entry,
            $context->player->getUsername(false),
            $context->profile->persona->value,
        );

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
