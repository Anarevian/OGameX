<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OGame\Models\Planet\Coordinate;

/**
 * What a bot believes about one coordinate.
 *
 * Bots select targets from this table and never from the planets table, so their decisions are
 * limited to information a player could have obtained in game.
 *
 * @property int $id
 * @property int $bot_user_id
 * @property int $galaxy
 * @property int $system
 * @property int $position
 * @property int $planet_type
 * @property string $source
 * @property int|null $owner_user_id
 * @property array<string, mixed>|null $payload
 * @property float $confidence
 * @property Carbon $observed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $bot
 * @property-read User|null $owner
 * @mixin \Eloquent
 */
#[Fillable([
    'bot_user_id',
    'galaxy',
    'system',
    'position',
    'planet_type',
    'source',
    'owner_user_id',
    'payload',
    'confidence',
    'observed_at',
])]
class BotIntel extends Model
{
    protected $table = 'bot_intel';

    /**
     * How quickly each intel source loses its value, in hours to half confidence.
     *
     * An espionage report is detailed but goes stale fast because stocks and defences change.
     * A galaxy scan holds its (much smaller) value for longer, because who owns a planet
     * changes rarely.
     */
    private const HALF_LIFE_HOURS = [
        'espionage' => 6,
        'phalanx' => 3,
        'battle' => 12,
        'galaxy_view' => 72,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'confidence' => 'float',
            'observed_at' => 'datetime',
        ];
    }

    /**
     * Get the bot that holds this belief.
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bot_user_id');
    }

    /**
     * Get the player who owned the coordinate when it was observed.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Get the observed coordinate.
     */
    public function coordinate(): Coordinate
    {
        return new Coordinate($this->galaxy, $this->system, $this->position);
    }

    /**
     * Get how much this belief is still worth, decayed by age.
     *
     * Returns the stored confidence halved once per source-specific half-life. A bot acting on a
     * low value here is knowingly guessing, which is how bad attacks happen.
     */
    public function currentConfidence(): float
    {
        $halfLife = self::HALF_LIFE_HOURS[$this->source] ?? 24;
        $ageHours = $this->observed_at->diffInMinutes(Carbon::now()) / 60;

        return $this->confidence * (2 ** (-$ageHours / $halfLife));
    }

    /**
     * Whether this belief is too old to act on without re-scouting first.
     */
    public function isStale(float $threshold = 0.25): bool
    {
        return $this->currentConfidence() < $threshold;
    }
}
