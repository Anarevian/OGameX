<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One decision taken by a bot, successful or not.
 *
 * Failures are kept deliberately: an action that threw usually means the bot mis-estimated
 * something, which is behaviour worth inspecting rather than an error to swallow.
 *
 * @property int $id
 * @property int $bot_user_id
 * @property string|null $tick_id
 * @property string $action
 * @property bool $succeeded
 * @property float|null $score
 * @property string|null $reason
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $bot
 * @mixin \Eloquent
 */
#[Fillable([
    'bot_user_id',
    'tick_id',
    'action',
    'succeeded',
    'score',
    'reason',
    'payload',
])]
class BotActionLog extends Model
{
    protected $table = 'bot_action_log';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'score' => 'float',
            'payload' => 'array',
        ];
    }

    /**
     * Get the bot that took this action.
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bot_user_id');
    }
}
