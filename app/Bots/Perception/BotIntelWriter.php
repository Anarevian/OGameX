<?php

namespace OGame\Bots\Perception;

use Illuminate\Support\Facades\Date;
use OGame\Models\BotIntel;
use OGame\Models\BotProfile;
use OGame\Models\EspionageReport;
use OGame\Models\Message;

/**
 * Turns espionage reports a bot has received into things the bot believes.
 *
 * This is the only path by which detailed knowledge — defences, fleets, stockpiles — enters
 * bot_intel, and bot_intel is the only thing raiding is allowed to read. That is what keeps a
 * bot's decisions limited to what a player in its position could actually know.
 *
 * Reports are found the same way a player finds them: through the messages in their own inbox.
 */
class BotIntelWriter
{
    /**
     * Read any espionage reports this bot has received since it last looked, and record what
     * they say.
     *
     * @return int Number of reports absorbed.
     */
    public function absorbReports(BotProfile $profile): int
    {
        $since = $profile->last_tick_at ?? Date::now()->subDay();

        $messages = Message::query()
            ->where('user_id', $profile->user_id)
            ->whereNotNull('espionage_report_id')
            ->where('created_at', '>=', $since)
            ->limit(25)
            ->get();

        if ($messages->isEmpty()) {
            return 0;
        }

        $reports = EspionageReport::whereIn('id', $messages->pluck('espionage_report_id'))->get();
        $absorbed = 0;

        foreach ($reports as $report) {
            $this->write($profile->user_id, $report);
            $absorbed++;
        }

        return $absorbed;
    }

    /**
     * Record one espionage report as intel.
     *
     * Only what the report actually contains is stored. A report taken with too few probes has
     * empty ships and defence sections, and that emptiness is recorded faithfully: the bot then
     * believes the planet is undefended, and finds out otherwise when it attacks. That is the
     * intended behaviour, not a gap — it is how a player's bad scouting gets punished.
     */
    public function write(int $botUserId, EspionageReport $report): void
    {
        $resources = $report->resources ?? [];
        $ships = $report->ships ?? [];
        $defence = $report->defense ?? [];

        BotIntel::updateOrCreate(
            [
                'bot_user_id' => $botUserId,
                'galaxy' => $report->planet_galaxy,
                'system' => $report->planet_system,
                'position' => $report->planet_position,
                'planet_type' => $report->planet_type,
            ],
            [
                'source' => 'espionage',
                'owner_user_id' => $report->planet_user_id,
                'payload' => [
                    'metal' => (int) ($resources['metal'] ?? 0),
                    'crystal' => (int) ($resources['crystal'] ?? 0),
                    'deuterium' => (int) ($resources['deuterium'] ?? 0),
                    // A report that revealed nothing is stored as "saw nothing", with a flag, so
                    // the raid scorer can tell "no defences" apart from "did not get to see".
                    'ships' => $ships,
                    'defence' => $defence,
                    'saw_military' => $ships !== [] || $defence !== [],
                    'ship_total' => array_sum(array_map('intval', $ships)),
                    'defence_total' => array_sum(array_map('intval', $defence)),
                    'player_name' => $report->player_info['player_name'] ?? null,
                ],
                'confidence' => 1.0,
                'observed_at' => $report->created_at ?? Date::now(),
            ],
        );
    }
}
