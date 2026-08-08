<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How a bot feels about another player.
 *
 * Because bots never send messages, this is where all social continuity lives. Attitude is
 * expressed through actions: who gets attacked back, whose alliance application is accepted,
 * whose ACS call is answered.
 *
 * @property int $id
 * @property int $bot_user_id
 * @property int $other_user_id
 * @property int $attitude
 * @property int $attacked_us_count
 * @property int $we_attacked_count
 * @property int $spied_us_count
 * @property float $resources_lost_to
 * @property float $resources_taken_from
 * @property Carbon|null $last_interaction_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $bot
 * @property-read User $other
 * @mixin \Eloquent
 */
#[Fillable([
    'bot_user_id',
    'other_user_id',
    'attitude',
    'attacked_us_count',
    'we_attacked_count',
    'spied_us_count',
    'resources_lost_to',
    'resources_taken_from',
    'last_interaction_at',
])]
class BotMemory extends Model
{
    protected $table = 'bot_memory';

    /**
     * Attitude below which the bot treats the other player as an enemy.
     */
    public const HOSTILE_THRESHOLD = -30;

    /**
     * Attitude above which the bot treats the other player as a friend.
     */
    public const FRIENDLY_THRESHOLD = 30;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attitude' => 'integer',
            'resources_lost_to' => 'float',
            'resources_taken_from' => 'float',
            'last_interaction_at' => 'datetime',
        ];
    }

    /**
     * Get the bot holding this memory.
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bot_user_id');
    }

    /**
     * Get the player this memory is about.
     */
    public function other(): BelongsTo
    {
        return $this->belongsTo(User::class, 'other_user_id');
    }

    /**
     * Whether the bot holds a grudge against this player.
     */
    public function isHostile(): bool
    {
        return $this->attitude <= self::HOSTILE_THRESHOLD;
    }

    /**
     * Whether the bot considers this player friendly.
     */
    public function isFriendly(): bool
    {
        return $this->attitude >= self::FRIENDLY_THRESHOLD;
    }

    /**
     * Shift the attitude by the given amount, clamped to the -100..100 range.
     */
    public function adjustAttitude(int $delta): void
    {
        $this->attitude = max(-100, min(100, $this->attitude + $delta));
        $this->last_interaction_at = Carbon::now();
    }
}
