<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Bots\Support\ReadsScalarOptions;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BotActionLog;
use OGame\Models\BotProfile;
use OGame\Models\Planet;

/**
 * Runs the universe forward at speed and reports what the bots actually did.
 *
 * Everything else in this system is verified a few ticks at a time, which proves the mechanics
 * work but says nothing about whether the population *behaves* — whether personas diverge, whether
 * activity is spread across the clock, whether the fairness caps hold under pressure. Those are
 * properties of weeks, not of ticks, and this is the only practical way to see them.
 *
 * **This is a development tool and must not be pointed at a live server.** It advances the
 * application clock with Date::setTestNow() so a week passes in minutes, which means every planet
 * it touches is written with a future timestamp. On a real server those planets would then sit
 * frozen until the wall clock caught up, and any human logged in at the time would see their own
 * game jump. It refuses to run outside local and testing environments for that reason.
 *
 * It is a tuning instrument, not a test. Read the distributions and adjust config/bots.php:
 *
 *  - If every persona's action mix looks the same, the persona weights are too close together.
 *  - If activity is flat across the 24 hours, the timezone or waking-hour model is wrong.
 *  - If one action dominates every persona, its score is on a different scale from the rest —
 *    see the rule in docs/npc-status.md section 4a.
 */
#[Description('Run the bot population forward over simulated days and report how it behaved.')]
#[Signature('ogamex:bots:simulate
                            {--days=7 : How many simulated days to run.}
                            {--step=2 : Hours of simulated time per step.}
                            {--keep-log : Keep the existing decision log instead of clearing it first.}
                            {--i-know-this-breaks-live-servers : Override the environment guard. Do not use on a live server.}')]
class SimulateBots extends Command
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
        // Advancing the clock is destructive on a live server: planets get future timestamps and
        // then sit frozen until real time catches up. Refuse by default.
        if (!app()->environment(['local', 'testing']) && !$this->option('i-know-this-breaks-live-servers')) {
            $this->error(sprintf(
                'Refusing to run in the "%s" environment. This command advances the application clock, which corrupts planet timestamps on a live server.',
                app()->environment(),
            ));
            $this->line('Run it against a development copy, or pass --i-know-this-breaks-live-servers if you are certain.');

            return self::FAILURE;
        }

        if (BotProfile::count() === 0) {
            $this->error('No bots to simulate. Run ogamex:bots:spawn first.');

            return self::FAILURE;
        }

        if (!config('bots.enabled')) {
            $this->error('Bots are disabled. Set BOTS_ENABLED=true before simulating.');

            return self::FAILURE;
        }

        $days = max(1, $this->intOption('days') ?? 7);
        $stepHours = max(1, $this->intOption('step') ?? 2);
        $steps = (int) ceil(($days * 24) / $stepHours);

        if (!$this->option('keep-log')) {
            BotActionLog::query()->delete();
        }

        $before = $this->snapshot();
        $startedAt = Date::now();

        $this->info(sprintf('Simulating %d days in %d steps of %dh...', $days, $steps, $stepHours));
        $bar = $this->output->createProgressBar($steps);
        $bar->start();

        for ($step = 0; $step < $steps; $step++) {
            Artisan::call('ogamex:bots:tick', ['--sync' => true]);
            Date::setTestNow(Date::now()->addHours($stepHours));
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $after = $this->snapshot();

        $this->reportActivityByHour($stepHours);
        $this->reportActionMix();
        $this->reportPersonaDivergence();
        $this->reportGrowth($before, $after);
        $this->reportFairness();

        // Leave the clock where the simulation started rather than in the future.
        Date::setTestNow();

        // Every bot is now scheduled days ahead in simulated time, which against the restored
        // clock means they would not act again for as long as the simulation ran. Re-anchor them
        // to the real present, spread over the next hour, or a second run finds nothing due and
        // the live server sits frozen.
        $this->rescheduleToPresent();
        $this->line(sprintf('<comment>Clock reset. Simulation covered %d days from %s.</comment>', $days, $startedAt->toDateTimeString()));

        return self::SUCCESS;
    }

    /**
     * Bring every bot's next turn back into the real present.
     */
    private function rescheduleToPresent(): void
    {
        $rescheduled = 0;

        foreach (BotProfile::whereNotNull('next_action_at')->get() as $profile) {
            if ($profile->next_action_at !== null && $profile->next_action_at->greaterThan(Date::now())) {
                $profile->next_action_at = Date::now()->addMinutes(random_int(1, 60));
                $profile->save();
                $rescheduled++;
            }
        }

        if ($rescheduled > 0) {
            $this->line(sprintf('<comment>Re-anchored %d bot schedules to the present.</comment>', $rescheduled));
        }
    }

    /**
     * Capture the economy of every bot, keyed by user id.
     *
     * @return array<int, int>
     */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach (BotProfile::pluck('user_id') as $userId) {
            $snapshot[(int) $userId] = (int) Planet::where('user_id', $userId)
                ->sum('metal_mine');
        }

        return $snapshot;
    }

    /**
     * When bots acted, across the clock.
     *
     * A healthy population has visible peaks and troughs. A flat line means the timezone spread
     * or the waking-hour model is not doing its job, and every bot is effectively always online.
     */
    private function reportActivityByHour(int $stepHours): void
    {
        $this->info('Activity by hour (UTC)');

        if ($stepHours > 1) {
            // The clock jumps in whole steps, so only every Nth hour can ever contain a decision.
            // The empty rows between are an artefact of sampling, not bots being asleep - run
            // with --step=1 to read the real shape of the day.
            $this->line(sprintf(
                '  <comment>Sampled every %dh, so only every %dth hour can show activity. Use --step=1 to see the true daily shape.</comment>',
                $stepHours,
                $stepHours,
            ));
        }

        // Query builder rather than Eloquent: these are aggregates, not models.
        $counts = DB::table('bot_action_log')
            ->selectRaw('HOUR(created_at) h, COUNT(*) c')
            ->groupBy('h')
            ->pluck('c', 'h')
            ->all();

        $peak = $counts === [] ? 0 : max($counts);

        for ($hour = 0; $hour < 24; $hour++) {
            $count = (int) ($counts[$hour] ?? 0);
            $width = $peak > 0 ? (int) round(($count / $peak) * 40) : 0;

            $this->line(sprintf('  %02d  %-40s %d', $hour, str_repeat('#', $width), $count));
        }

        $this->newLine();
    }

    /**
     * What the population spent its decisions on.
     */
    private function reportActionMix(): void
    {
        $this->info('Action mix');

        $rows = DB::table('bot_action_log')
            ->selectRaw('action, COUNT(*) c, SUM(succeeded = 0) fails, ROUND(AVG(score), 2) avg_score')
            ->groupBy('action')
            ->orderByDesc('c')
            ->get();

        $total = max(1, (int) $rows->sum('c'));

        $this->table(
            ['Action', 'Count', 'Share', 'Failed', 'Avg score'],
            $rows->map(fn ($row) => [
                (string) $row->action,
                (int) $row->c,
                sprintf('%.1f%%', ((int) $row->c / $total) * 100),
                (int) $row->fails,
                (string) $row->avg_score,
            ])->all()
        );

        $failed = (int) $rows->sum('fails');
        if ($failed > 0) {
            $this->warn(sprintf('%d actions were rejected by the game. Every one is a bot proposing something illegal.', $failed));
        }
    }

    /**
     * Whether the personas actually behave differently.
     *
     * This is the headline number. If two personas' rows look alike, they are the same playstyle
     * wearing different names, and the weights in config/bots.php need pulling apart.
     */
    private function reportPersonaDivergence(): void
    {
        $this->info('Decision share by persona');

        $personas = BotProfile::pluck('persona', 'user_id');
        $byPersona = [];

        $aggregate = DB::table('bot_action_log')
            ->selectRaw('bot_user_id, action, COUNT(*) c')
            ->groupBy('bot_user_id', 'action')
            ->get();

        foreach ($aggregate as $row) {
            $persona = $personas[$row->bot_user_id] ?? null;
            if ($persona === null) {
                continue;
            }

            $key = is_string($persona) ? $persona : $persona->value;
            $action = (string) $row->action;
            $byPersona[$key][$action] = ($byPersona[$key][$action] ?? 0) + (int) $row->c;
        }

        if ($byPersona === []) {
            $this->line('  No decisions recorded.');
            $this->newLine();

            return;
        }

        $actions = [];
        foreach ($byPersona as $counts) {
            $actions = array_unique(array_merge($actions, array_keys($counts)));
        }
        sort($actions);

        $rows = [];
        ksort($byPersona);
        foreach ($byPersona as $persona => $counts) {
            $total = max(1, array_sum($counts));
            $row = [$persona];

            foreach ($actions as $action) {
                $share = ($counts[$action] ?? 0) / $total;
                $row[] = $share > 0 ? sprintf('%.0f%%', $share * 100) : '-';
            }

            $rows[] = $row;
        }

        $this->table(array_merge(['Persona'], $actions), $rows);
    }

    /**
     * How much the economies moved.
     *
     * @param array<int, int> $before
     * @param array<int, int> $after
     */
    private function reportGrowth(array $before, array $after): void
    {
        $this->info('Economic growth (total mine levels)');

        $grew = 0;
        $flat = 0;
        $totalGain = 0;

        foreach ($after as $userId => $levels) {
            $gain = $levels - ($before[$userId] ?? 0);
            $totalGain += $gain;

            if ($gain > 0) {
                $grew++;
            } else {
                $flat++;
            }
        }

        $this->line(sprintf('  %d bots grew, %d stood still, %d mine levels gained in total.', $grew, $flat, $totalGain));

        if ($flat > $grew) {
            $this->warn('  More bots stood still than grew.');
            $this->line('  <comment>Some of this is expected: ghosts never tick, vacationers may be away, and a developed');
            $this->line('  account\'s next mine has a long payback. Check ogamex:bots:inspect on a stalled bot before');
            $this->line('  concluding a category never wins.</comment>');
        }

        $this->newLine();
    }

    /**
     * Whether the fairness caps held.
     *
     * The engine enforces no newbie protection, so these caps are the only thing protecting human
     * players. A simulation is the cheapest place to find out they are not working.
     */
    private function reportFairness(): void
    {
        $this->info('Aggression towards humans');

        $botUserIds = BotProfile::pluck('user_id')->all();

        $raids = BotActionLog::where('action', 'raid')->where('succeeded', true)->get();
        $onHumans = 0;
        $perHuman = [];

        foreach ($raids as $raid) {
            $targetId = $raid->payload['target_user_id'] ?? null;

            if ($targetId === null || in_array((int) $targetId, $botUserIds, true)) {
                continue;
            }

            $onHumans++;
            $perHuman[(int) $targetId] = ($perHuman[(int) $targetId] ?? 0) + 1;
        }

        $this->line(sprintf('  %d raids total, %d of them against human players.', $raids->count(), $onHumans));

        if ($perHuman !== []) {
            arsort($perHuman);
            foreach (array_slice($perHuman, 0, 5, true) as $userId => $count) {
                $player = $this->playerServiceFactory->make((int) $userId);
                $this->line(sprintf('    %s: raided %d times', $player->getUsername(), $count));
            }

            $worst = max($perHuman);
            $this->warn(sprintf(
                '  Worst affected human was raided %d times. Compare against bots.fairness limits before shipping.',
                $worst
            ));
        }

        $this->newLine();
    }
}
