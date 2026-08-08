<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OGame\Enums\BotLod;
use OGame\Enums\BotPersona;

/**
 * Drives one bot account. One row per bot user.
 *
 * @property int $id
 * @property int $user_id
 * @property BotPersona $persona
 * @property float $skill
 * @property float $aggression
 * @property float $risk_tolerance
 * @property string $timezone
 * @property array<string, mixed> $activity_profile
 * @property array<string, mixed>|null $state
 * @property BotLod $lod
 * @property Carbon|null $next_action_at
 * @property Carbon|null $last_tick_at
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'persona',
    'skill',
    'aggression',
    'risk_tolerance',
    'timezone',
    'activity_profile',
    'state',
    'lod',
    'next_action_at',
    'last_tick_at',
    'enabled',
])]
class BotProfile extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'persona' => BotPersona::class,
            'lod' => BotLod::class,
            'skill' => 'float',
            'aggression' => 'float',
            'risk_tolerance' => 'float',
            'activity_profile' => 'array',
            'state' => 'array',
            'next_action_at' => 'datetime',
            'last_tick_at' => 'datetime',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Get the user account this profile drives.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this bot should be ticked at all.
     *
     * Ghosts are accounts that stopped playing forever, so they are never ticked regardless of
     * their enabled flag. This is checked before any work is queued for the bot.
     */
    public function isTickable(): bool
    {
        return $this->enabled && !$this->persona->isDormant();
    }

    /**
     * Get the bot's current local time, in its own timezone.
     *
     * Bots are spread across timezones so the population's activity does not all peak at once.
     */
    public function localNow(): Carbon
    {
        return Carbon::now($this->timezone);
    }

    /**
     * Whether the bot is inside its activity window right now.
     *
     * The window may wrap past midnight, which is expressed as an end hour above 24 (a bot awake
     * from 09:00 to 01:00 has hours [9, 25]).
     */
    public function isAwake(): bool
    {
        if (!$this->isTickable()) {
            return false;
        }

        $hours = $this->activity_profile['awake_hours'] ?? null;
        if (!is_array($hours) || count($hours) !== 2) {
            return true;
        }

        $start = (int) $hours[0];
        $end = (int) $hours[1];

        // A zero-length window means the account never plays.
        if ($start === $end) {
            return false;
        }

        $hour = (int) $this->localNow()->format('G');

        if ($end <= 24) {
            return $hour >= $start && $hour < $end;
        }

        // Wrapping window: awake late into the night and again from the start hour.
        return $hour >= $start || $hour < ($end - 24);
    }
}
