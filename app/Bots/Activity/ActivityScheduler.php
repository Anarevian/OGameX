<?php

namespace OGame\Bots\Activity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use OGame\Models\BotProfile;

/**
 * Decides when a bot next sits down to play.
 *
 * This is the single most important piece of the whole system for believability. A bot that
 * acts every N minutes, forever, reads as a script no matter how good its decisions are; a bot
 * that plays in short bursts, sleeps for eight hours, skips a day here and there and comes back
 * to a full building queue reads as a person, even when its decisions are mediocre.
 *
 * The model is: within its waking hours a bot has a handful of sessions per day. A session is a
 * burst of a few actions a couple of minutes apart. Between sessions it does nothing at all,
 * while its queues keep running — which is exactly what an absent human looks like.
 */
class ActivityScheduler
{
    /**
     * Seconds between two actions inside the same session.
     *
     * Deliberately above the admin panel's default 10-second bot-detection threshold for
     * reaction time, and jittered, so the server's own NPCs never look like the scripted
     * clients that detection is meant to catch.
     */
    private const WITHIN_SESSION_SECONDS = [25, 210];

    /**
     * Work out when this bot should act next, given it has just acted.
     *
     * @param BotProfile $profile
     * @param int $actionsRemaining How many actions are left in the current session.
     * @return Carbon|null Null when the bot should never act again.
     */
    public function nextActionAt(BotProfile $profile, int $actionsRemaining): Carbon|null
    {
        if (!$profile->isTickable()) {
            return null;
        }

        // Still mid-session: come back in a minute or two, like someone clicking around.
        if ($actionsRemaining > 0 && $profile->isAwake()) {
            return Date::now()->addSeconds(random_int(self::WITHIN_SESSION_SECONDS[0], self::WITHIN_SESSION_SECONDS[1]));
        }

        return $this->nextSessionStart($profile);
    }

    /**
     * Work out when this bot's next session begins.
     */
    public function nextSessionStart(BotProfile $profile): Carbon|null
    {
        if (!$profile->isTickable()) {
            return null;
        }

        $localNow = $profile->localNow();
        $gap = $this->averageSessionGapMinutes($profile, $localNow);

        // Spread the gap by +/-40% so sessions are not evenly spaced through the day.
        $minutes = (int) round($gap * (random_int(60, 140) / 100));
        $candidate = $localNow->copy()->addMinutes(max(5, $minutes));

        // If the next session would land while the bot is asleep, push it to the start of its
        // next waking window instead, plus a little jitter so it does not log in on the hour.
        $candidate = $this->pushIntoWakingHours($profile, $candidate);

        // Some days a person simply does not show up.
        if ($this->rollSkipDay($profile)) {
            $candidate = $this->pushIntoWakingHours($profile, $candidate->copy()->addDay());
        }

        return $candidate->copy()->setTimezone(config('app.timezone', 'UTC'));
    }

    /**
     * How many actions this session should contain.
     */
    public function rollSessionActions(BotProfile $profile): int
    {
        /** @var array<int, int> $range */
        $range = $profile->activity_profile['session_actions'] ?? [1, 5];
        $min = max(0, (int) ($range[0] ?? 1));
        $max = max($min, (int) ($range[1] ?? $min));

        if ($max === 0) {
            return 0;
        }

        return random_int(max(1, $min), $max);
    }

    /**
     * Average minutes between sessions, derived from sessions-per-day and the waking window.
     *
     * Sessions are packed into waking hours only, so a bot awake for eight hours a day with
     * three sessions plays roughly every two and a half hours during those eight hours, not
     * every eight hours around the clock.
     */
    private function averageSessionGapMinutes(BotProfile $profile, Carbon $localNow): float
    {
        /** @var array<int, int> $range */
        $range = $profile->activity_profile['sessions_per_day'] ?? [1, 3];
        $min = max(1, (int) ($range[0] ?? 1));
        $max = max($min, (int) ($range[1] ?? $min));
        $sessions = random_int($min, $max);

        // Weekends change how much people play, in both directions.
        $weekendFactor = (float) ($profile->activity_profile['weekend_factor'] ?? 1.0);
        if ($localNow->isWeekend() && $weekendFactor > 0) {
            $sessions = max(1, (int) round($sessions * $weekendFactor));
        }

        $wakingMinutes = $this->wakingHours($profile) * 60;

        return $wakingMinutes / max(1, $sessions);
    }

    /**
     * How many hours a day this bot is awake.
     */
    private function wakingHours(BotProfile $profile): float
    {
        /** @var array<int, int> $hours */
        $hours = $profile->activity_profile['awake_hours'] ?? [8, 23];
        $start = (int) ($hours[0] ?? 8);
        $end = (int) ($hours[1] ?? 23);

        return max(1.0, (float) ($end - $start));
    }

    /**
     * Move a timestamp forward until it lands inside the bot's waking hours.
     */
    private function pushIntoWakingHours(BotProfile $profile, Carbon $candidate): Carbon
    {
        /** @var array<int, int> $hours */
        $hours = $profile->activity_profile['awake_hours'] ?? [8, 23];
        $start = (int) ($hours[0] ?? 8);
        $end = (int) ($hours[1] ?? 23);

        if ($start === $end) {
            // Never awake. Push far enough out that the bot is effectively dormant without
            // needing a null, which would be indistinguishable from "finished forever".
            return $candidate->copy()->addDay();
        }

        for ($day = 0; $day < 3; $day++) {
            $hour = (int) $candidate->format('G');

            $awake = $end <= 24
                ? ($hour >= $start && $hour < $end)
                : ($hour >= $start || $hour < ($end - 24));

            if ($awake) {
                return $candidate;
            }

            // Before the window opens today: wait for it. After it closes: wait for tomorrow.
            if ($hour < $start) {
                return $candidate->copy()->setTime($start, random_int(0, 59), random_int(0, 59));
            }

            $candidate = $candidate->copy()->addDay()->setTime($start, random_int(0, 59), random_int(0, 59));
        }

        return $candidate;
    }

    /**
     * Roll whether the bot skips today entirely.
     */
    private function rollSkipDay(BotProfile $profile): bool
    {
        $chance = (float) ($profile->activity_profile['skip_day_chance'] ?? 0.0);

        if ($chance <= 0) {
            return false;
        }

        return (random_int(1, 1000) / 1000) <= $chance;
    }
}
