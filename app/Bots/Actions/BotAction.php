<?php

namespace OGame\Bots\Actions;

use OGame\Bots\Brain\ActionCandidate;
use OGame\Bots\Brain\BotContext;

/**
 * One kind of thing a bot knows how to do.
 *
 * An action proposes zero or more concrete candidates for the current game state. It must not
 * change anything while proposing: the brain decides which candidates actually run.
 *
 * Actions are expected to propose only what is legally possible and currently affordable. The
 * game's queue services cancel a queued item when its resources cannot be paid at start time,
 * so proposing something unaffordable does not fail loudly, it silently wastes the bot's turn.
 */
interface BotAction
{
    /**
     * Propose everything this action could do right now.
     *
     * @return array<int, ActionCandidate>
     */
    public function propose(BotContext $context): array;
}
