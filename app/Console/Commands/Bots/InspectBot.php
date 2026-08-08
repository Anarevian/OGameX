<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Bots\Support\ReadsScalarOptions;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BotActionLog;
use OGame\Models\BotIntel;
use OGame\Models\BotMemory;
use OGame\Models\BotProfile;
use OGame\Models\User;

/**
 * Shows everything about one bot: who it is, what it believes, and why it did what it did.
 *
 * This is the first thing to reach for when a bot misbehaves. The decision log answers "why did
 * it do that", the intel answers "what did it think was true at the time", and the memory answers
 * "why did it pick that target".
 */
#[Description('Show a bot\'s profile, beliefs, relationships and recent decisions.')]
#[Signature('ogamex:bots:inspect
                            {user? : Bot user id or username. Omit to list all bots.}
                            {--decisions=15 : How many recent decisions to show.}')]
class InspectBot extends Command
{
    use ReadsScalarOptions;

    public function __construct(private readonly PlayerServiceFactory $playerServiceFactory)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $argument = $this->argument('user');

        if ($argument === null || $argument === '') {
            return $this->listAll();
        }

        $profile = $this->findProfile((string) $argument);

        if ($profile === null) {
            $this->error(sprintf('No bot found for "%s".', (string) $argument));

            return self::FAILURE;
        }

        $this->showProfile($profile);
        $this->showEmpire($profile);
        $this->showDecisions($profile);
        $this->showIntel($profile);
        $this->showRelationships($profile);

        return self::SUCCESS;
    }

    /**
     * List the whole population at a glance.
     */
    private function listAll(): int
    {
        $profiles = BotProfile::with('user')->orderBy('persona')->get();

        if ($profiles->isEmpty()) {
            $this->info('No bots exist. Run ogamex:bots:spawn to create some.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Persona', 'Skill', 'LOD', 'Stance', 'Next action'],
            $profiles->map(fn (BotProfile $profile) => [
                $profile->user_id,
                $profile->user->username,
                $profile->persona->value,
                sprintf('%.2f', $profile->skill),
                $profile->lod->value,
                $profile->state['stance'] ?? '-',
                $profile->next_action_at?->diffForHumans() ?? 'never',
            ])->all()
        );

        return self::SUCCESS;
    }

    /**
     * Resolve a bot by user id or username.
     */
    private function findProfile(string $identifier): BotProfile|null
    {
        if (ctype_digit($identifier)) {
            $profile = BotProfile::where('user_id', (int) $identifier)->first();

            if ($profile !== null) {
                return $profile;
            }
        }

        $userId = User::where('username', $identifier)->value('id');

        return $userId === null ? null : BotProfile::where('user_id', $userId)->first();
    }

    /**
     * Who this bot is.
     */
    private function showProfile(BotProfile $profile): void
    {
        $this->info(sprintf('%s (user %d)', $profile->user->username, $profile->user_id));

        $this->table(['Field', 'Value'], [
            ['Persona', $profile->persona->value],
            ['Skill', sprintf('%.2f', $profile->skill)],
            ['Aggression', sprintf('%.2f', $profile->aggression)],
            ['Risk tolerance', sprintf('%.2f', $profile->risk_tolerance)],
            ['Timezone', $profile->timezone . ' (local ' . $profile->localNow()->format('H:i') . ')'],
            ['Awake now', $profile->isAwake() ? 'yes' : 'no'],
            ['Waking hours', implode('-', $profile->activity_profile['awake_hours'] ?? [])],
            ['Level of detail', $profile->lod->value],
            ['Stance', $profile->state['stance'] ?? '-'],
            ['Last tick', $profile->last_tick_at?->diffForHumans() ?? 'never'],
            ['Next action', $profile->next_action_at?->diffForHumans() ?? 'never'],
            ['Enabled', $profile->enabled ? 'yes' : 'no'],
        ]);
    }

    /**
     * What it owns.
     */
    private function showEmpire(BotProfile $profile): void
    {
        $player = $this->playerServiceFactory->make($profile->user_id, true);

        $rows = [];
        foreach ($player->planets->all() as $planet) {
            $rows[] = [
                $planet->getPlanetName(),
                $planet->getPlanetCoordinates()->asString(),
                $planet->getObjectLevel('metal_mine') . '/' . $planet->getObjectLevel('crystal_mine') . '/' . $planet->getObjectLevel('deuterium_synthesizer'),
                (int) $planet->energy()->get(),
                number_format((float) $planet->metal()->get()),
                $planet->getBuildingCount() . '/' . $planet->getPlanetFieldMax(),
            ];
        }

        $this->info('Empire');
        $this->table(['Planet', 'Coords', 'Mines m/c/d', 'Energy', 'Metal', 'Fields'], $rows);
    }

    /**
     * Why it did what it did.
     */
    private function showDecisions(BotProfile $profile): void
    {
        $limit = max(1, $this->intOption('decisions') ?? 15);

        $decisions = BotActionLog::where('bot_user_id', $profile->user_id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $this->info(sprintf('Last %d decisions', $decisions->count()));

        if ($decisions->isEmpty()) {
            $this->line('  Nothing logged yet.');

            return;
        }

        $this->table(
            ['When', 'Action', 'OK', 'Score', 'Reason'],
            $decisions->map(fn (BotActionLog $entry) => [
                $entry->created_at?->diffForHumans() ?? '-',
                $entry->action,
                $entry->succeeded ? 'yes' : 'NO',
                $entry->score === null ? '-' : sprintf('%.2f', $entry->score),
                $entry->succeeded ? $entry->reason : ($entry->payload['error'] ?? $entry->reason),
            ])->all()
        );
    }

    /**
     * What it believes about the neighbourhood, and how much of that it still trusts.
     */
    private function showIntel(BotProfile $profile): void
    {
        $intel = BotIntel::where('bot_user_id', $profile->user_id)
            ->orderByDesc('observed_at')
            ->limit(10)
            ->get();

        $this->info(sprintf('Intel (%d records held)', BotIntel::where('bot_user_id', $profile->user_id)->count()));

        if ($intel->isEmpty()) {
            $this->line('  Has not scouted anything.');

            return;
        }

        $this->table(
            ['Coords', 'Source', 'Seen', 'Confidence', 'Stale', 'Believed loot'],
            $intel->map(function (BotIntel $record) {
                $payload = $record->payload ?? [];
                $loot = (int) (($payload['metal'] ?? 0) + ($payload['crystal'] ?? 0) + ($payload['deuterium'] ?? 0));

                return [
                    $record->coordinate()->asString(),
                    $record->source,
                    $record->observed_at->diffForHumans(),
                    sprintf('%.0f%%', $record->currentConfidence() * 100),
                    $record->isStale() ? 'yes' : 'no',
                    number_format($loot),
                ];
            })->all()
        );
    }

    /**
     * How it feels about the people it has met.
     */
    private function showRelationships(BotProfile $profile): void
    {
        $memories = BotMemory::where('bot_user_id', $profile->user_id)
            ->orderBy('attitude')
            ->limit(10)
            ->get();

        $this->info('Relationships');

        if ($memories->isEmpty()) {
            $this->line('  Has not met anyone.');

            return;
        }

        $this->table(
            ['Player', 'Attitude', 'They attacked', 'We attacked', 'They spied'],
            $memories->map(function (BotMemory $memory) {
                $name = User::where('id', $memory->other_user_id)->value('username') ?? ('#' . $memory->other_user_id);
                $label = $memory->isHostile() ? 'hostile' : ($memory->isFriendly() ? 'friendly' : 'neutral');

                return [
                    $name,
                    sprintf('%d (%s)', $memory->attitude, $label),
                    $memory->attacked_us_count,
                    $memory->we_attacked_count,
                    $memory->spied_us_count,
                ];
            })->all()
        );
    }
}
