<?php

namespace OGame\Bots\Brain;

use Closure;

/**
 * One concrete thing a bot could do right now, with a score saying how much it wants to.
 *
 * Actions produce candidates; the brain scores, sorts and executes them. Keeping the execution
 * in a closure means an action class can capture whatever it already computed while proposing,
 * instead of recomputing it when the candidate wins.
 */
class ActionCandidate
{
    /**
     * @param string $action Machine name recorded in the action log, e.g. build_building.
     * @param string $category Scoring category the stance modifier applies to.
     * @param float $score Raw utility, before stance, persona and noise are applied.
     * @param string $reason Short human-readable justification, for the log and for tuning.
     * @param array<string, mixed> $payload Action-specific detail for the log.
     * @param Closure(): void $execute Performs the action. May throw; the brain records that.
     */
    public function __construct(
        public readonly string $action,
        public readonly string $category,
        public float $score,
        public readonly string $reason,
        public readonly array $payload,
        public readonly Closure $execute,
    ) {
    }
}
