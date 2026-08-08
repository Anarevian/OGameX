<?php

namespace OGame\Bots\Perception;

use Illuminate\Support\Facades\Date;
use OGame\Models\BattleReport;
use OGame\Models\BotIntel;
use OGame\Models\BotProfile;
use OGame\Models\Message;
use OGame\Models\Planet;

/**
 * Reads the battle reports a bot has received and turns them into memory and intel.
 *
 * A bot learns about combat the same way a player does — from the reports in its own inbox — and
 * two different things come out of each one:
 *
 *  - **Memory.** Who did this, and what it cost. That is what later makes the bot attack them
 *    back, refuse their alliance application, or join an ally's strike against them. Since bots
 *    never speak, this is the only way a relationship can exist at all.
 *  - **Intel.** A battle reveals what the other side actually had. That is far better information
 *    than an espionage report, and it is exactly what a player takes away from a fight, so it
 *    overwrites what the bot believed about that coordinate.
 */
class BotBattleObserver
{
    /**
     * Read every battle report received since the bot last acted.
     *
     * @return int Number of reports absorbed.
     */
    public function absorbReports(BotProfile $profile): int
    {
        $since = $profile->last_tick_at ?? Date::now()->subDay();

        $reportIds = Message::query()
            ->where('user_id', $profile->user_id)
            ->whereNotNull('battle_report_id')
            ->where('created_at', '>=', $since)
            ->limit(25)
            ->pluck('battle_report_id');

        if ($reportIds->isEmpty()) {
            return 0;
        }

        $absorbed = 0;

        foreach (BattleReport::whereIn('id', $reportIds)->get() as $report) {
            $this->absorb($profile, $report);
            $absorbed++;
        }

        return $absorbed;
    }

    /**
     * Absorb one battle report.
     */
    public function absorb(BotProfile $profile, BattleReport $report): void
    {
        $memoryService = app(BotMemoryService::class);

        $attacker = $report->attacker ?? [];
        $defender = $report->defender ?? [];

        $attackerId = isset($attacker['player_id']) ? (int) $attacker['player_id'] : null;
        $defenderId = isset($defender['player_id']) ? (int) $defender['player_id'] : null;

        $loot = $report->loot ?? [];
        $lootValue = (float) (($loot['metal'] ?? 0) + ($loot['crystal'] ?? 0) + ($loot['deuterium'] ?? 0));

        if ($defenderId === $profile->user_id && $attackerId !== null && $attackerId !== $profile->user_id) {
            // The bot was attacked. What it lost is its own losses plus whatever was carried off.
            $lost = (float) ($defender['resource_loss'] ?? 0) + $lootValue;
            $memoryService->recordAttackSuffered($profile->user_id, $attackerId, $lost);

            return;
        }

        if ($attackerId === $profile->user_id && $defenderId !== null && $defenderId !== $profile->user_id) {
            $memoryService->recordAttackMade($profile->user_id, $defenderId, $lootValue);

            // The fight showed what the target really had, which is better than any espionage
            // report the bot was working from. Record it so the next raid is better informed.
            $this->recordCombatIntel($profile, $report, $defenderId, $defender);
        }
    }

    /**
     * Write what the battle revealed about the defender's planet.
     *
     * @param array<string, mixed> $defender
     */
    private function recordCombatIntel(BotProfile $profile, BattleReport $report, int $defenderId, array $defender): void
    {
        /** @var array<string, int> $units */
        $units = is_array($defender['units'] ?? null) ? $defender['units'] : [];

        $planetType = Planet::where('galaxy', $report->planet_galaxy)
            ->where('system', $report->planet_system)
            ->where('planet', $report->planet_position)
            ->value('planet_type') ?? 1;

        BotIntel::updateOrCreate(
            [
                'bot_user_id' => $profile->user_id,
                'galaxy' => $report->planet_galaxy,
                'system' => $report->planet_system,
                'position' => $report->planet_position,
                'planet_type' => (int) $planetType,
            ],
            [
                'source' => 'battle',
                'owner_user_id' => $defenderId,
                'payload' => [
                    // A fight empties the planet of whatever was carried off, so what the bot now
                    // believes is on the ground is close to nothing.
                    'metal' => 0,
                    'crystal' => 0,
                    'deuterium' => 0,
                    'ships' => $units,
                    'defence' => [],
                    'saw_military' => true,
                    'ship_total' => array_sum(array_map('intval', $units)),
                    'defence_total' => 0,
                ],
                'confidence' => 1.0,
                'observed_at' => $report->created_at ?? Date::now(),
            ],
        );
    }
}
