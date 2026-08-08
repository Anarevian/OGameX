<?php

namespace OGame\Bots\Actions;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Brain\ActionCandidate;
use OGame\Bots\Brain\BotContext;
use OGame\Bots\Support\BotFleetService;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\BotActionLog;
use OGame\Models\BotIntel;
use OGame\Models\BotProfile;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\PlanetService;

/**
 * Sends attacks at targets the bot has scouted.
 *
 * Two rules govern this class, and both matter more than the scoring does.
 *
 * First, **targets come from bot_intel, never from the planets table**. The bot attacks what it
 * believes is there, which may be out of date. Attacking into a defence that was built after the
 * last scan is not a bug: it is the fog of war doing its job, and it is what makes raiding a real
 * decision rather than an oracle.
 *
 * Second, **every attack on a human is checked against the fairness caps** in config('bots.fairness').
 * The engine does not enforce newbie protection — PlayerService::isNewbie() and isStrong() are
 * only used to draw the galaxy view — so this class is the only thing standing between a human
 * player and being farmed out of the game by the server's own NPCs. Bot-on-bot raiding is
 * deliberately left uncapped: that is the universe having a life of its own.
 */
class RaidAction implements BotAction
{
    /**
     * Ships used for raiding, in the order they are drawn on.
     *
     * Cargo first, because a raid that cannot carry the loot home is pointless, then escorts.
     *
     * @var array<int, string>
     */
    private const CARGO = ['large_cargo', 'small_cargo'];

    /**
     * @var array<int, string>
     */
    private const ESCORT = ['cruiser', 'battle_ship', 'heavy_fighter', 'light_fighter'];

    public function __construct(private readonly BotFleetService $fleetService)
    {
    }

    /**
     * @inheritDoc
     */
    public function propose(BotContext $context): array
    {
        $player = $context->player;

        if ($context->profile->aggression < 0.25 || !$this->fleetService->hasFreeSlot($player)) {
            return [];
        }

        $candidates = [];

        foreach ($context->planets() as $planet) {
            $target = $this->pickTarget($context, $planet);
            if ($target === null) {
                continue;
            }

            [$intel, $expectedLoot] = $target;
            $coordinate = $intel->coordinate();

            $fleet = $this->assembleFleet($context, $planet, $intel, $expectedLoot);
            if ($fleet === null) {
                continue;
            }

            if (!$this->fleetService->canSend($player, $planet, $coordinate, PlanetType::Planet, BotFleetService::MISSION_ATTACK, $fleet)) {
                continue;
            }

            // Expected loot against how confident the bot is that the intel still holds. A bot
            // acting on a stale report scores its raid lower, which is why high-skill bots
            // re-scout first and low-skill ones charge in.
            $confidence = $intel->currentConfidence();
            $score = ($expectedLoot / 50000) * $context->profile->aggression * (0.4 + $confidence);

            $candidates[] = new ActionCandidate(
                action: 'raid',
                category: 'raid',
                score: $score,
                reason: sprintf(
                    'raid %s, expect %dk loot, intel %d%% fresh',
                    $coordinate->asString(),
                    (int) round($expectedLoot / 1000),
                    (int) round($confidence * 100),
                ),
                payload: [
                    'planet_id' => $planet->getPlanetId(),
                    'target' => $coordinate->asString(),
                    'target_user_id' => $intel->owner_user_id,
                    'expected_loot' => (int) $expectedLoot,
                    'ships' => $fleet->toArray(),
                ],
                execute: fn () => $this->fleetService->send(
                    $player,
                    $planet,
                    $coordinate,
                    PlanetType::Planet,
                    BotFleetService::MISSION_ATTACK,
                    $fleet,
                ),
            );

            break;
        }

        return $candidates;
    }

    /**
     * Choose the best target this bot currently believes in.
     *
     * @return array{BotIntel, float}|null The intel record and the loot it implies.
     */
    private function pickTarget(BotContext $context, PlanetService $planet): array|null
    {
        $home = $planet->getPlanetCoordinates();

        $records = BotIntel::where('bot_user_id', $context->player->getId())
            ->where('source', 'espionage')
            ->where('galaxy', $home->galaxy)
            ->whereNotNull('owner_user_id')
            ->orderByDesc('observed_at')
            ->limit(40)
            ->get();

        $best = null;
        $bestLoot = 0.0;

        foreach ($records as $intel) {
            // Too old to act on at all: the bot would be guessing entirely.
            if ($intel->isStale(0.1)) {
                continue;
            }

            $payload = $intel->payload ?? [];

            // A defended target is not worth raiding with cargo and a light escort.
            if ((int) ($payload['defence_total'] ?? 0) > 0 || (int) ($payload['ship_total'] ?? 0) > 0) {
                continue;
            }

            $loot = (
                (int) ($payload['metal'] ?? 0)
                + (int) ($payload['crystal'] ?? 0)
                + (int) ($payload['deuterium'] ?? 0)
            ) / 2; // Raids take half of what is on the planet.

            if ($loot < 5000) {
                continue;
            }

            if (!$this->isPermittedTarget($context, $intel)) {
                continue;
            }

            if ($loot > $bestLoot) {
                $best = $intel;
                $bestLoot = $loot;
            }
        }

        return $best === null ? null : [$best, $bestLoot];
    }

    /**
     * Whether the bot is allowed to attack this target.
     *
     * Bot-on-bot raiding is unrestricted. Everything below exists to protect human players, and
     * is the only protection they have, since the engine enforces none.
     */
    private function isPermittedTarget(BotContext $context, BotIntel $intel): bool
    {
        $targetUserId = $intel->owner_user_id;
        if ($targetUserId === null || $targetUserId === $context->player->getId()) {
            return false;
        }

        // Another NPC: let them fight.
        if (BotProfile::where('user_id', $targetUserId)->exists()) {
            return true;
        }

        $target = User::find($targetUserId);
        if ($target === null) {
            return false;
        }

        /** @var array<string, mixed> $fairness */
        $fairness = config('bots.fairness', []);

        // Newly registered humans are left completely alone.
        $graceDays = (int) ($fairness['new_player_grace_days'] ?? 7);
        if ($target->created_at !== null && $target->created_at->greaterThan(Date::now()->subDays($graceDays))) {
            return false;
        }

        // Below a points floor, a human is not a target at any level of aggression.
        $minPoints = (int) ($fairness['min_target_points'] ?? 0);
        if ($minPoints > 0) {
            $points = (int) (Highscore::where('player_id', $targetUserId)->value('general') ?? 0);
            if ($points < $minPoints) {
                return false;
            }
        }

        // How often this one bot may hit this one human.
        $cooldownHours = (int) ($fairness['raid_cooldown_hours'] ?? 0);
        if ($cooldownHours > 0 && $this->raidedSince($context->player->getId(), $targetUserId, Date::now()->subHours($cooldownHours)) > 0) {
            return false;
        }

        // How often the whole NPC population may hit this human in a day.
        $maxPerDay = (int) ($fairness['max_attacks_per_human_per_day'] ?? 0);
        if ($maxPerDay > 0 && $this->raidedSince(null, $targetUserId, Date::now()->subDay()) >= $maxPerDay) {
            return false;
        }

        // How many NPC fleets may be in the air at this human at once, so bots cannot gang up.
        $maxSimultaneous = (int) ($fairness['max_simultaneous_attacks_per_human'] ?? 0);
        if ($maxSimultaneous > 0 && $this->incomingBotAttacks($targetUserId) >= $maxSimultaneous) {
            return false;
        }

        return true;
    }

    /**
     * Count logged raids against a player since a moment in time.
     *
     * @param int|null $botUserId Restrict to one bot, or null for every bot.
     */
    private function raidedSince(int|null $botUserId, int $targetUserId, Carbon $since): int
    {
        $query = BotActionLog::where('action', 'raid')
            ->where('succeeded', true)
            ->where('created_at', '>=', $since)
            ->whereJsonContains('payload->target_user_id', $targetUserId);

        if ($botUserId !== null) {
            $query->where('bot_user_id', $botUserId);
        }

        return $query->count();
    }

    /**
     * Count hostile NPC fleets currently in the air towards a player.
     */
    private function incomingBotAttacks(int $targetUserId): int
    {
        // FleetMission records the destination planet, not its owner, so the target's planets
        // are resolved first.
        return FleetMission::query()
            ->where('mission_type', BotFleetService::MISSION_ATTACK)
            ->where('processed', 0)
            ->whereNull('parent_id')
            ->whereIn('planet_id_to', Planet::where('user_id', $targetUserId)->pluck('id'))
            ->whereIn('user_id', BotProfile::pluck('user_id'))
            ->count();
    }

    /**
     * Put together a raiding fleet sized for the loot, or null if the planet cannot field one.
     */
    private function assembleFleet(BotContext $context, PlanetService $planet, BotIntel $intel, float $expectedLoot): UnitCollection|null
    {
        // Cargo capacity needed for the loot. A skilled bot sizes this properly; a careless one
        // under-sends and leaves resources behind, which is a very common human mistake.
        $skillError = 1.3 - (0.5 * $context->profile->skill);
        $largeCargoCapacity = 25000;
        $cargosNeeded = max(1, (int) ceil(($expectedLoot / $largeCargoCapacity) / $skillError));

        $wanted = [];
        $remaining = $cargosNeeded;

        foreach (self::CARGO as $machineName) {
            if ($remaining < 1) {
                break;
            }

            $available = $planet->getObjectAmount($machineName);
            if ($available < 1) {
                continue;
            }

            $take = min($available, $remaining);
            $wanted[$machineName] = $take;
            $remaining -= $take;
        }

        if ($wanted === []) {
            return null;
        }

        // A small escort, because an undefended-looking planet is not always undefended.
        foreach (self::ESCORT as $machineName) {
            $available = $planet->getObjectAmount($machineName);
            if ($available < 1) {
                continue;
            }

            $escort = (int) ceil($available * (0.1 + (0.3 * $context->profile->risk_tolerance)));
            if ($escort > 0) {
                $wanted[$machineName] = $escort;
            }
        }

        try {
            $fleet = $this->fleetService->buildFleet($planet, $wanted);
        } catch (Exception) {
            return null;
        }

        return $fleet->getAmount() > 0 ? $fleet : null;
    }
}
