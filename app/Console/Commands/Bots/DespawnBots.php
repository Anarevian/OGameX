<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Bots\Support\ReadsScalarOptions;
use OGame\Enums\BotPersona;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BotProfile;
use Throwable;

/**
 * Removes NPC accounts and everything belonging to them.
 *
 * Deleting the user cascades to the bot tables through their foreign keys, so the only work
 * here is going through PlayerService::delete() for the game-side records (planets, queues,
 * fleet missions, messages, highscores) that are not covered by a cascade.
 */
#[Description('Remove NPC (bot) player accounts and all of their game data.')]
#[Signature('ogamex:bots:despawn
                            {--persona= : Only remove bots of this persona.}
                            {--limit= : Only remove up to this many bots.}
                            {--all : Remove every bot. Required when no other filter is given.}
                            {--force : Skip the confirmation prompt.}')]
class DespawnBots extends Command
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
        $query = BotProfile::query();

        $persona = $this->scalarOption('persona');
        if ($persona !== null) {
            $personaEnum = BotPersona::tryFrom($persona);
            if ($personaEnum === null) {
                $this->error(sprintf('Unknown persona "%s". Valid values: %s', $persona, implode(', ', array_column(BotPersona::cases(), 'value'))));

                return self::FAILURE;
            }

            $query->where('persona', $personaEnum->value);
        }

        $limit = $this->intOption('limit');
        if ($limit !== null) {
            $query->limit($limit);
        }

        $hasFilter = $persona !== null || $limit !== null;

        if (!$hasFilter && !$this->option('all')) {
            $this->error('Refusing to delete every bot without --all. Pass --all, --persona or --limit.');

            return self::FAILURE;
        }

        $userIds = $query->pluck('user_id')->all();

        if ($userIds === []) {
            $this->info('No matching bots found.');

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm(sprintf('Delete %d NPC account(s) and all of their planets, fleets and messages?', count($userIds)))) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $failed = 0;
        $progressBar = $this->output->createProgressBar(count($userIds));
        $progressBar->start();

        foreach ($userIds as $userId) {
            try {
                // PlayerService::delete() removes the planets, queues, fleet missions, messages,
                // highscore and tech rows, then the user itself. The bot_* rows go with the user
                // through their cascading foreign keys.
                $this->playerServiceFactory->make($userId, true)->delete();
                $deleted++;
            } catch (Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn(sprintf('Could not delete bot user %d: %s', $userId, $e->getMessage()));
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info(sprintf('Removed %d NPC account(s). %d remaining.', $deleted, BotProfile::count()));

        if ($failed > 0) {
            $this->warn(sprintf('%d account(s) could not be removed.', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
