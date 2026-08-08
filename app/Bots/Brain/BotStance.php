<?php

namespace OGame\Bots\Brain;

/**
 * The posture a bot is currently playing in.
 *
 * Stances change slowly, over hours or days, and act as multipliers on the utility scores of
 * whole categories of action. They are what stops a bot from oscillating between building mines
 * and building ships every few minutes: within a stance its behaviour is coherent, and the
 * stance itself only moves when something meaningful happens.
 *
 * Phase 1 only produces the economic stances. Defending, Raiding and Recovering are set once
 * bots gain fleets and can be attacked, in Phase 2.
 */
enum BotStance: string
{
    /**
     * Building up mines, energy and storage.
     */
    case Economising = 'economising';

    /**
     * Pushing research, usually towards a specific unlock.
     */
    case Researching = 'researching';

    /**
     * Building units: ships for a fleeter, defence for a turtle.
     */
    case Militarising = 'militarising';

    /**
     * Going after colonies and expanding the empire.
     */
    case Expanding = 'expanding';

    /**
     * Under threat, prioritising survival.
     */
    case Defending = 'defending';

    /**
     * Out hunting for loot.
     */
    case Raiding = 'raiding';

    /**
     * Rebuilding after a loss.
     */
    case Recovering = 'recovering';

    /**
     * Nothing worth doing right now.
     */
    case Idle = 'idle';

    /**
     * How strongly this stance favours a category of action.
     *
     * Returns a multiplier applied to every candidate of that category. The values are
     * deliberately gentle: a stance should bias a bot, not blinker it, or the behaviour becomes
     * as rigid as the hardcoded build order this design set out to avoid.
     */
    public function modifierFor(string $category): float
    {
        return match ($this) {
            self::Economising => match ($category) {
                'economy' => 1.6,
                'infrastructure' => 1.4,
                'research' => 0.9,
                'fleet', 'defence' => 0.5,
                default => 1.0,
            },
            self::Researching => match ($category) {
                'research' => 1.7,
                'economy' => 1.0,
                'infrastructure' => 1.1,
                'fleet', 'defence' => 0.6,
                default => 1.0,
            },
            self::Militarising => match ($category) {
                'fleet', 'defence' => 1.7,
                'economy' => 0.8,
                'infrastructure' => 1.1,
                'research' => 0.9,
                default => 1.0,
            },
            self::Expanding => match ($category) {
                'expansion' => 2.0,
                'research' => 1.2,
                'economy' => 1.0,
                'infrastructure' => 1.0,
                'fleet', 'defence' => 0.6,
                default => 1.0,
            },
            self::Defending => match ($category) {
                'defence' => 2.0,
                'fleet' => 1.2,
                'economy' => 0.6,
                'research' => 0.5,
                default => 1.0,
            },
            self::Raiding => match ($category) {
                'fleet' => 1.6,
                'raid' => 2.0,
                'economy' => 0.7,
                default => 1.0,
            },
            self::Recovering => match ($category) {
                'defence' => 1.5,
                'economy' => 1.2,
                'fleet' => 1.1,
                default => 1.0,
            },
            self::Idle => 1.0,
        };
    }
}
