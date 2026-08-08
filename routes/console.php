<?php

use OGame\Console\Commands\Bots\PruneBotActionLog;
use OGame\Console\Commands\Bots\TickBots;
use OGame\Console\Commands\Scheduler\CleanupWreckFields;
use OGame\Console\Commands\Scheduler\DarkMatterRegenerateCommand;
use OGame\Console\Commands\Scheduler\DeleteOldMessages;
use OGame\Console\Commands\Scheduler\GenerateAllianceHighscores;
use OGame\Console\Commands\Scheduler\GenerateHighscoreRanks;
use OGame\Console\Commands\Scheduler\GenerateHighscores;
use OGame\Console\Commands\Scheduler\ResetDebrisFields;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Schedule::command(GenerateHighscores::class)->everyFiveMinutes();
// Alliance highscores should run after player highscores since they depend on them
Schedule::command(GenerateAllianceHighscores::class)->everyFiveMinutes();
// Generates ranks for both player and alliance highscores
Schedule::command(GenerateHighscoreRanks::class)->everyFiveMinutes();

// Reset empty debris fields weekly on Monday at 1:00 AM
Schedule::command(ResetDebrisFields::class)->weeklyOn(1, '1:00');

// Clean up wreck fields hourly
Schedule::command(CleanupWreckFields::class)->hourly()->withoutOverlapping();

// Delete messages once they have aged out of the seven-day retention window
Schedule::command(DeleteOldMessages::class)->hourly()->withoutOverlapping();

// Process Dark Matter regeneration every 5 minutes
Schedule::command(DarkMatterRegenerateCommand::class)->everyFiveMinutes()->withoutOverlapping();

// Wake up NPC (bot) players that are due to act. The command only selects due bots and queues
// one job each, so the sweep stays cheap; withoutOverlapping stops a slow sweep from stacking.
// Does nothing at all while BOTS_ENABLED is false.
Schedule::command(TickBots::class)->everyMinute()->withoutOverlapping();

// Keep the NPC decision log bounded.
Schedule::command(PruneBotActionLog::class)->dailyAt('4:15')->withoutOverlapping();
