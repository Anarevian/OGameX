<?php

namespace OGame\Enums;

/**
 * Level of detail at which a bot is simulated.
 *
 * Simulating every bot at full fidelity every minute is wasteful when nobody is watching, so
 * bots are simulated in tiers. The classification itself is implemented in Phase 5; the column
 * exists from Phase 0 so no migration is needed later.
 */
enum BotLod: string
{
    /**
     * Fully simulated: inside a human's observation radius, or with an active fleet mission.
     */
    case Full = 'full';

    /**
     * Economy advanced analytically at a coarse interval, decisions simplified.
     */
    case Abstract = 'abstract';

    /**
     * Not simulated at all (ghosts, and bots outside their activity window).
     */
    case Dormant = 'dormant';
}
