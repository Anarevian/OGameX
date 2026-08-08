<?php

namespace OGame\Bots\Social;

use OGame\Bots\Perception\BotMemoryService;
use OGame\Models\BotMemory;
use OGame\Models\BotProfile;
use OGame\Services\AllianceService;
use OGame\Services\BuddyService;
use Throwable;

/**
 * Handles the social requests waiting in a bot's inbox.
 *
 * These are reactions rather than decisions, so they run during the tick instead of competing
 * for an action slot: a player deals with a pending alliance application or buddy request when
 * they notice it, not instead of upgrading a mine.
 *
 * Everything here is expressed through mechanics, never prose. Bots are silent (see the plan's
 * decision 4), so an application is accepted or rejected and a buddy request is answered or
 * ignored, with no accompanying text. Whatever the engine itself sends as a system notification
 * is the engine's business, not the bot writing a message.
 */
class BotSocialService
{
    public function __construct(
        private readonly AllianceService $allianceService,
        private readonly BuddyService $buddyService,
        private readonly BotMemoryService $memoryService,
    ) {
    }

    /**
     * Deal with everything waiting for this bot.
     */
    public function handlePending(BotProfile $profile): void
    {
        $this->handleAllianceApplications($profile);
        $this->handleBuddyRequests($profile);
        $this->warmToAllies($profile);
    }

    /**
     * Accept or reject applications to an alliance this bot leads.
     *
     * The decision is entirely attitude-driven, which is what makes a grudge visible without a
     * word: someone who has been raiding this bot does not get let into its alliance, and does
     * not get told why.
     */
    public function handleAllianceApplications(BotProfile $profile): void
    {
        $user = $profile->user;
        $allianceId = $user->alliance_id;

        if ($allianceId === null) {
            return;
        }

        try {
            $applications = $this->allianceService->getPendingApplications($allianceId);
        } catch (Throwable) {
            return;
        }

        foreach ($applications as $application) {
            $attitude = $this->memoryService->attitudeTowards($profile->user_id, (int) $application->user_id);

            try {
                if ($attitude <= BotMemory::HOSTILE_THRESHOLD) {
                    $this->allianceService->rejectApplication($application->id, $profile->user_id);

                    continue;
                }

                // A bot that keeps to itself lets applications sit, exactly as a real leader who
                // is not paying attention would. Sociability rises with how outgoing the persona
                // is, approximated by how willing it is to deal with other people at all.
                if ((random_int(1, 100) / 100) > $this->sociability($profile)) {
                    continue;
                }

                $this->allianceService->acceptApplication($application->id, $profile->user_id);

                // Letting someone in is a friendly act, and it should show in the relationship.
                $memory = $this->memoryService->remember($profile->user_id, (int) $application->user_id);
                $memory->adjustAttitude(15);
                $memory->save();
            } catch (Throwable) {
                // A rank without the right permission, a race with a human admin, an application
                // that vanished. None of it should end the bot's turn.
                continue;
            }
        }
    }

    /**
     * Answer or ignore buddy requests.
     *
     * People ignore buddy requests all the time, so a bot that answers every single one
     * immediately would be more suspicious than one that leaves some hanging.
     */
    public function handleBuddyRequests(BotProfile $profile): void
    {
        try {
            $requests = $this->buddyService->getReceivedRequests($profile->user_id);
        } catch (Throwable) {
            return;
        }

        foreach ($requests as $request) {
            $attitude = $this->memoryService->attitudeTowards($profile->user_id, (int) $request->sender_user_id);

            try {
                if ($attitude <= BotMemory::HOSTILE_THRESHOLD) {
                    $this->buddyService->rejectRequest($request->id, $profile->user_id);

                    continue;
                }

                if ((random_int(1, 100) / 100) > $this->sociability($profile)) {
                    // Left sitting in the inbox, which is what most requests get.
                    continue;
                }

                $this->buddyService->acceptRequest($request->id, $profile->user_id);

                $memory = $this->memoryService->remember($profile->user_id, (int) $request->sender_user_id);
                $memory->adjustAttitude(10);
                $memory->save();
            } catch (Throwable) {
                continue;
            }
        }
    }

    /**
     * Warm slightly towards fellow alliance members.
     *
     * This is the only thing in the system that moves attitude upwards on its own, and it exists
     * so relationships are not a one-way slide into hostility. Sharing an alliance with someone
     * for a while should count for something, and it is what later makes a bot answer an ACS call
     * from one ally and not another.
     */
    public function warmToAllies(BotProfile $profile): void
    {
        $allianceId = $profile->user->alliance_id;

        if ($allianceId === null) {
            return;
        }

        try {
            $members = $this->allianceService->getAllianceMembers($allianceId);
        } catch (Throwable) {
            return;
        }

        foreach ($members as $member) {
            $otherId = (int) $member->user_id;

            if ($otherId === $profile->user_id) {
                continue;
            }

            $memory = $this->memoryService->remember($profile->user_id, $otherId);

            // Only a nudge, and only up to friendly. An ally who is also raiding this bot stays
            // an enemy: what they do outweighs the fact that they share a tag.
            if ($memory->attitude < BotMemory::FRIENDLY_THRESHOLD) {
                $memory->adjustAttitude(2);
                $memory->save();
            }
        }
    }

    /**
     * How readily this bot deals with other people.
     *
     * Derived from skill rather than being its own trait: an attentive player clears their
     * pending requests, a careless one lets them pile up.
     */
    private function sociability(BotProfile $profile): float
    {
        return 0.2 + (0.6 * $profile->skill);
    }
}
