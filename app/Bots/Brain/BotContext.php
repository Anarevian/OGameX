<?php

namespace OGame\Bots\Brain;

use OGame\Enums\BotPersona;
use OGame\Models\BotProfile;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Everything an action needs to know about the bot it is proposing for.
 *
 * Passed to every action's propose() call so actions stay stateless and cheap to test.
 */
class BotContext
{
    /**
     * @param PlayerService $player The bot's player service.
     * @param BotProfile $profile The bot's profile.
     * @param BotStance $stance The bot's current posture.
     * @param string $tickId Groups every decision taken in this tick in the action log.
     */
    public function __construct(
        public readonly PlayerService $player,
        public readonly BotProfile $profile,
        public readonly BotStance $stance,
        public readonly string $tickId,
    ) {
    }

    /**
     * Get the bot's persona.
     */
    public function persona(): BotPersona
    {
        return $this->profile->persona;
    }

    /**
     * Get every planet this bot owns.
     *
     * @return array<int, PlanetService>
     */
    public function planets(): array
    {
        return $this->player->planets->all();
    }

    /**
     * Get one of the persona's focus weights (economy, research, fleet, defence).
     */
    public function focus(string $key): float
    {
        $config = $this->persona()->config();
        /** @var array<string, mixed> $focus */
        $focus = is_array($config['focus'] ?? null) ? $config['focus'] : [];

        return (float) ($focus[$key] ?? 0.5);
    }

    /**
     * Apply this bot's skill-scaled decision noise to a score.
     *
     * A perfectly rational bot always upgrades the mathematically best mine, which over a week
     * produces a suspiciously optimal build order. Low-skill bots get a wide random multiplier
     * so they visibly make worse choices; high-skill bots get almost none.
     */
    public function withNoise(float $score): float
    {
        $spread = 1.0 - $this->profile->skill;

        if ($spread <= 0.01) {
            return $score;
        }

        $factor = 1.0 + ((random_int(-100, 100) / 100) * $spread * 0.8);

        return $score * max(0.05, $factor);
    }
}
