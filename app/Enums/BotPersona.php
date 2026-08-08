<?php

namespace OGame\Enums;

/**
 * The playstyles a bot player can have.
 *
 * Each persona is backed by a configuration block in config/bots.php that defines its
 * character class, growth behaviour, activity profile and (from Phase 1 onwards) the
 * utility weights that drive its decisions.
 */
enum BotPersona: string
{
    case Miner = 'miner';
    case Raider = 'raider';
    case Explorer = 'explorer';
    case Turtle = 'turtle';
    case Fleeter = 'fleeter';
    case Casual = 'casual';
    case Trader = 'trader';
    case Ghost = 'ghost';
    case Vacationer = 'vacationer';

    /**
     * Get the human-readable name of the persona.
     */
    public function getName(): string
    {
        return match ($this) {
            self::Miner => 'Miner',
            self::Raider => 'Raider',
            self::Explorer => 'Explorer',
            self::Turtle => 'Turtle',
            self::Fleeter => 'Fleeter',
            self::Casual => 'Casual',
            self::Trader => 'Trader',
            self::Ghost => 'Ghost',
            self::Vacationer => 'Vacationer',
        };
    }

    /**
     * Whether this persona ever takes actions.
     *
     * Ghosts are accounts that registered, played briefly and then stopped forever. They are
     * never ticked, which is both realistic universe texture and free performance.
     */
    public function isDormant(): bool
    {
        return $this === self::Ghost;
    }

    /**
     * Get the configuration block for this persona from config/bots.php.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $config = config('bots.personas.' . $this->value);

        return is_array($config) ? $config : [];
    }
}
