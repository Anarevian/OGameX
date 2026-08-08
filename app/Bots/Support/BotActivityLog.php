<?php

namespace OGame\Bots\Support;

use Illuminate\Support\Facades\Log;
use OGame\Models\BotActionLog;
use Throwable;

/**
 * Mirrors every bot decision into a plain log file as it is taken.
 *
 * The bot_action_log table is the authoritative record and the one all the tooling queries, but a
 * table is not something an operator can watch. This writes the same decisions to a daily file
 * under storage/logs (bots-YYYY-MM-DD.log), which means following it shows the universe playing
 * itself in real time, the activity survives the retention prune that eventually clears the table,
 * and anything that reads log files — docker logs, a shipper, a search — can see what the NPCs are
 * doing without touching the database.
 *
 * It never throws. A logging failure must not cost a bot its turn, so every path here is
 * best-effort and swallows its own errors.
 */
class BotActivityLog
{
    /**
     * Write one decision to the log file.
     *
     * @param BotActionLog $entry The decision as it was persisted.
     * @param string $username The bot's account name, so the line is readable without a join.
     * @param string $persona The bot's playstyle, which is usually why it chose what it chose.
     */
    public function record(BotActionLog $entry, string $username, string $persona): void
    {
        if (!config('bots.log.file', true)) {
            return;
        }

        try {
            Log::channel((string) config('bots.log.channel', 'bots'))->info(
                $this->line($entry, $username, $persona),
                $this->context($entry),
            );
        } catch (Throwable) {
            // A missing channel or an unwritable log directory is not worth losing a turn over.
        }
    }

    /**
     * Format the decision as a single readable line.
     *
     * Field order is fixed and the columns are padded so a tailed log stays scannable: who, what,
     * whether it worked, how strongly it wanted to, and why.
     */
    private function line(BotActionLog $entry, string $username, string $persona): string
    {
        $reason = $entry->succeeded
            ? (string) $entry->reason
            : (string) ($entry->payload['error'] ?? $entry->reason ?? 'failed');

        return sprintf(
            '%-16s %-10s %-16s %-4s %5s  %s',
            $username,
            $persona,
            $entry->action,
            $entry->succeeded ? 'ok' : 'FAIL',
            $entry->score === null ? '-' : sprintf('%.2f', $entry->score),
            $reason,
        );
    }

    /**
     * The structured half of the line.
     *
     * Deliberately small on success: the reason already says everything a human needs, and a full
     * payload on every line makes the file unreadable. A failure gets the payload, because working
     * out what the bot thought it was doing is the entire point of looking at a failure.
     *
     * @return array<string, mixed>
     */
    private function context(BotActionLog $entry): array
    {
        $context = [
            'bot' => $entry->bot_user_id,
            'tick' => $entry->tick_id,
        ];

        if (!$entry->succeeded) {
            $context['payload'] = $entry->payload;
        }

        return $context;
    }
}
