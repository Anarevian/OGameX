<?php

namespace OGame\Bots\Support;

use OGame\Enums\BotPersona;

/**
 * Turns a persona's configuration ranges into one concrete bot's traits.
 *
 * Two Raiders should not be the same Raider. Everything a persona defines as a range is rolled
 * once here, at spawn time, and then stays fixed for the life of the account, so a bot has a
 * stable character rather than re-rolling its personality every tick.
 */
class BotPersonaRoller
{
    /**
     * Timezones bots are spread across.
     *
     * Weighted towards Europe, which is where a European browser game's population mostly sits,
     * but deliberately not exclusively so. The point is that the server's activity has several
     * overlapping peaks instead of one, so the galaxy is never uniformly awake or uniformly dead.
     *
     * @var array<int, string>
     */
    private const TIMEZONES = [
        'Europe/Berlin', 'Europe/Berlin', 'Europe/Berlin',
        'Europe/Madrid', 'Europe/Madrid',
        'Europe/Warsaw', 'Europe/Warsaw',
        'Europe/London', 'Europe/London',
        'Europe/Rome',
        'Europe/Kyiv',
        'Europe/Istanbul',
        'Europe/Moscow',
        'America/Sao_Paulo',
        'America/New_York',
        'America/Los_Angeles',
        'Asia/Jakarta',
        'Asia/Manila',
        'Asia/Kolkata',
        'Australia/Sydney',
    ];

    /**
     * Roll the persistent trait values for a new bot.
     *
     * @return array{skill: float, aggression: float, risk_tolerance: float, timezone: string, activity_profile: array<string, mixed>}
     */
    public function roll(BotPersona $persona): array
    {
        $config = $persona->config();

        return [
            'skill' => $this->rollRange($config['skill'] ?? [0.5, 0.5]),
            'aggression' => $this->rollRange($config['aggression'] ?? [0.5, 0.5]),
            'risk_tolerance' => $this->rollRange($config['risk_tolerance'] ?? [0.5, 0.5]),
            'timezone' => self::TIMEZONES[random_int(0, count(self::TIMEZONES) - 1)],
            'activity_profile' => $this->rollActivityProfile($persona),
        ];
    }

    /**
     * Build the session model for a new bot.
     *
     * The persona supplies the shape; this shifts it per bot so that two Miners in the same
     * timezone still do not log in at the same times. Without the shift the population would
     * act in visible waves.
     *
     * @return array<string, mixed>
     */
    public function rollActivityProfile(BotPersona $persona): array
    {
        $config = $persona->config();
        /** @var array<string, mixed> $activity */
        $activity = is_array($config['activity'] ?? null) ? $config['activity'] : [];

        /** @var array<int, int> $awake */
        $awake = is_array($activity['awake_hours'] ?? null) ? $activity['awake_hours'] : [8, 23];
        $start = (int) ($awake[0] ?? 8);
        $end = (int) ($awake[1] ?? 23);

        // Ghosts and other never-playing personas keep a zero-length window.
        if ($start !== $end) {
            $shift = random_int(-2, 2);
            $start = max(0, min(23, $start + $shift));
            $end = max($start + 1, $end + $shift);
        }

        /** @var array<int, int> $sessions */
        $sessions = is_array($activity['sessions_per_day'] ?? null) ? $activity['sessions_per_day'] : [1, 3];
        /** @var array<int, int> $actions */
        $actions = is_array($activity['session_actions'] ?? null) ? $activity['session_actions'] : [1, 5];

        return [
            'awake_hours' => [$start, $end],
            'sessions_per_day' => [(int) ($sessions[0] ?? 1), (int) ($sessions[1] ?? 3)],
            'session_actions' => [(int) ($actions[0] ?? 1), (int) ($actions[1] ?? 5)],
            // Fraction by which this bot's activity changes at the weekend. Above 1 means it
            // plays more on Saturday and Sunday, below 1 means less.
            'weekend_factor' => round(random_int(60, 160) / 100, 2),
            // Chance per day that the bot simply does not show up at all.
            'skip_day_chance' => round(random_int(2, 18) / 100, 2),
        ];
    }

    /**
     * Roll a value from a [min, max] range.
     *
     * @param array<int, float|int> $range
     */
    private function rollRange(array $range): float
    {
        $min = (float) ($range[0] ?? 0.0);
        $max = (float) ($range[1] ?? $min);

        if ($max <= $min) {
            return round($min, 3);
        }

        return round($min + (random_int(0, 1000) / 1000) * ($max - $min), 3);
    }
}
