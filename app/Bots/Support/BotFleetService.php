<?php

namespace OGame\Bots\Support;

use Exception;
use OGame\Factories\GameMissionFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Sends fleets on a bot's behalf.
 *
 * Everything goes through FleetMissionService::createNewFromPlanet(), which runs the same sanity
 * checks a human's dispatch does: resources present, units present, fleet slots free, vacation
 * mode, attack block, target valid. A bot therefore cannot dispatch anything a player could not.
 *
 * FleetMissionService is player-scoped — it is constructed with a PlayerService — so it must be
 * resolved against the bot's own player, never taken from the container as a shared instance.
 */
class BotFleetService
{
    /**
     * Mission type ids, matching the GameMission classes.
     */
    public const MISSION_ATTACK = 1;

    public const MISSION_TRANSPORT = 3;

    public const MISSION_DEPLOYMENT = 4;

    public const MISSION_ESPIONAGE = 6;

    public const MISSION_COLONISATION = 7;

    public const MISSION_RECYCLE = 8;

    public const MISSION_EXPEDITION = 15;

    /**
     * Get the fleet mission service bound to this bot.
     */
    public function serviceFor(PlayerService $player): FleetMissionService
    {
        return resolve(FleetMissionService::class, ['player' => $player]);
    }

    /**
     * Whether the bot has a free fleet slot.
     */
    public function hasFreeSlot(PlayerService $player): bool
    {
        return $player->getFleetSlotsInUse() < $player->getFleetSlotsMax();
    }

    /**
     * Whether the bot has a free expedition slot.
     */
    public function hasFreeExpeditionSlot(PlayerService $player): bool
    {
        return $player->getExpeditionSlotsInUse() < $player->getExpeditionSlotsMax();
    }

    /**
     * Build a unit collection from machine name => amount pairs, skipping anything the planet
     * does not actually have.
     *
     * @param array<string, int> $units
     * @throws Exception
     */
    public function buildFleet(PlanetService $planet, array $units): UnitCollection
    {
        $collection = new UnitCollection();

        foreach ($units as $machineName => $amount) {
            if ($amount < 1) {
                continue;
            }

            $available = $planet->getObjectAmount($machineName);
            if ($available < 1) {
                continue;
            }

            $collection->addUnit(
                ObjectService::getUnitObjectByMachineName($machineName),
                min($amount, $available),
            );
        }

        return $collection;
    }

    /**
     * Check whether a mission would be accepted, without sending it.
     *
     * Used while proposing candidates so the brain does not waste a decision on something the
     * game would reject.
     */
    public function canSend(
        PlayerService $player,
        PlanetService $planet,
        Coordinate $target,
        PlanetType $targetType,
        int $missionType,
        UnitCollection $units,
    ): bool {
        if ($units->getAmount() < 1) {
            return false;
        }

        if (!$this->hasFreeSlot($player)) {
            return false;
        }

        try {
            $missionObject = resolve(GameMissionFactory::class)->getMissionById($missionType, [
                'fleetMissionService' => $this->serviceFor($player),
                'messageService' => resolve(MessageService::class, ['player' => $player]),
            ]);

            return $missionObject->isMissionPossible($planet, $target, $targetType, $units)->possible;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Dispatch a mission.
     *
     * @throws Exception
     */
    public function send(
        PlayerService $player,
        PlanetService $planet,
        Coordinate $target,
        PlanetType $targetType,
        int $missionType,
        UnitCollection $units,
        Resources|null $resources = null,
        float $speedPercent = 10,
        int $holdingHours = 0,
    ): void {
        $this->serviceFor($player)->createNewFromPlanet(
            $planet,
            $target,
            $targetType,
            $missionType,
            $units,
            $resources ?? new Resources(0, 0, 0, 0),
            $speedPercent,
            $holdingHours,
        );
    }
}
