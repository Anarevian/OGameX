<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use OGame\Bots\Support\BotActivityLog;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\BotActionLog;
use OGame\Models\BotProfile;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

/**
 * Coverage for the decision log surfaces: ogamex:bots:log and the file mirror.
 *
 * These deliberately write their own log rows rather than ticking bots. What is under test is the
 * reporting, not the deciding, and the behavioural suites are slow enough already.
 */
class BotLogTest extends TestCase
{
    private BotProfile $profile;

    private string $exportPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bots.enabled' => true]);
        $this->removeAllBots();
        BotActionLog::query()->delete();

        $this->profile = $this->spawnOne();
        $this->exportPath = storage_path('app/testing/bot-log-' . uniqid() . '.txt');
    }

    protected function tearDown(): void
    {
        BotActionLog::query()->delete();
        $this->removeAllBots();

        if (File::exists($this->exportPath)) {
            File::delete($this->exportPath);
        }

        Date::setTestNow();

        parent::tearDown();
    }

    private function removeAllBots(): void
    {
        $factory = resolve(PlayerServiceFactory::class);

        foreach (BotProfile::pluck('user_id') as $userId) {
            $factory->make((int) $userId, true)->delete();
        }
    }

    /**
     * One fresh bot, which is all these tests need: a real account so the username and persona
     * joins have something to resolve.
     */
    private function spawnOne(): BotProfile
    {
        $this->assertSame(0, Artisan::call('ogamex:bots:spawn', [
            '--count' => 1,
            '--persona' => 'miner',
            '--near-humans' => '0',
            '--fresh' => true,
        ]));

        return BotProfile::firstOrFail();
    }

    /**
     * Run the command against split output streams.
     *
     * Artisan::call() hands the command a plain buffer, and a plain buffer is not a console, so
     * everything lands in one place and the stdout/stderr split cannot be observed. Passing an
     * output that really does have two streams is the only way to prove the machine formats are
     * not contaminated by the human-facing summary.
     *
     * @param array<string, mixed> $options
     * @return array{lines: array<int, string>, stderr: string}
     */
    private function runLog(array $options): array
    {
        $output = new class () extends BufferedOutput implements ConsoleOutputInterface {
            public BufferedOutput $errors;

            public function __construct()
            {
                parent::__construct();

                $this->errors = new BufferedOutput();
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->errors;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
            }

            public function section(): ConsoleSectionOutput
            {
                throw new RuntimeException('Sections are not used by this command.');
            }
        };

        $this->assertSame(0, Artisan::call('ogamex:bots:log', $options, $output));

        return [
            'lines' => array_values(array_filter(
                explode(PHP_EOL, $output->fetch()),
                fn (string $line) => trim($line) !== '',
            )),
            'stderr' => $output->errors->fetch(),
        ];
    }

    /**
     * Write a decision straight into the log.
     *
     * @param array<string, mixed> $payload
     */
    private function logDecision(string $action, bool $succeeded = true, float $score = 1.0, string $reason = 'because', array $payload = []): BotActionLog
    {
        return BotActionLog::create([
            'bot_user_id' => $this->profile->user_id,
            'tick_id' => '00000000-0000-0000-0000-000000000001',
            'action' => $action,
            'succeeded' => $succeeded,
            'score' => $score,
            'reason' => $reason,
            'payload' => $payload,
        ]);
    }

    /**
     * The default view: a chronological stream naming the bot, what it did and why.
     */
    public function testLogShowsDecisionsInOrder(): void
    {
        $this->logDecision('build_building', reason: 'metal_mine 4, payback 2h');
        $this->logDecision('research', reason: 'energy_technology 2, interest 0.6');

        $this->assertSame(0, Artisan::call('ogamex:bots:log'));
        $output = Artisan::output();

        $this->assertStringContainsString($this->profile->user->username, $output);
        $this->assertStringContainsString('miner', $output);
        $this->assertStringContainsString('metal_mine 4, payback 2h', $output);

        // Oldest first: a log reads forwards even though the limit is taken from the newest end.
        $this->assertLessThan(
            strpos($output, 'energy_technology'),
            strpos($output, 'metal_mine 4'),
        );
    }

    /**
     * A failure shows the exception rather than what the bot meant to do, which is the only part
     * worth reading when something went wrong.
     */
    public function testFailedFilterShowsTheError(): void
    {
        $this->logDecision('build_building');
        $this->logDecision('raid', succeeded: false, reason: 'raid 1:2:3', payload: ['error' => 'no free fleet slot']);

        $this->assertSame(0, Artisan::call('ogamex:bots:log', ['--failed' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('no free fleet slot', $output);
        $this->assertStringNotContainsString('build_building', $output);
        $this->assertStringContainsString('FAIL', $output);
    }

    /**
     * The filters narrow to what was asked for and nothing else.
     */
    public function testFiltersNarrowTheStream(): void
    {
        $this->logDecision('build_building');
        $this->logDecision('espionage');
        $this->logDecision('raid');

        Artisan::call('ogamex:bots:log', ['--action' => ['espionage']]);
        $output = Artisan::output();
        $this->assertStringContainsString('espionage', $output);
        $this->assertStringNotContainsString('build_building', $output);

        // By username rather than id, because that is what an operator has in front of them.
        Artisan::call('ogamex:bots:log', ['--user' => $this->profile->user->username]);
        $this->assertStringContainsString('espionage', Artisan::output());

        Artisan::call('ogamex:bots:log', ['--persona' => 'raider']);
        $this->assertStringContainsString('No decisions matched', Artisan::output());
    }

    /**
     * A time span excludes everything older than it.
     */
    public function testSinceExcludesOlderDecisions(): void
    {
        $old = $this->logDecision('build_building', reason: 'ancient history');
        $old->created_at = Date::now()->subDays(3);
        $old->save();

        $this->logDecision('research', reason: 'recent news');

        $this->assertSame(0, Artisan::call('ogamex:bots:log', ['--since' => '2h']));
        $output = Artisan::output();

        $this->assertStringContainsString('recent news', $output);
        $this->assertStringNotContainsString('ancient history', $output);
    }

    /**
     * A bad filter is rejected with an explanation instead of silently returning nothing, which
     * would read as "the bots did nothing" and send someone debugging the wrong thing.
     */
    public function testBadFiltersAreRejected(): void
    {
        $this->assertSame(1, Artisan::call('ogamex:bots:log', ['--persona' => 'nonsense']));
        $this->assertStringContainsString('Unknown persona', Artisan::output());

        $this->assertSame(1, Artisan::call('ogamex:bots:log', ['--format' => 'xml']));
        $this->assertStringContainsString('Unknown format', Artisan::output());

        $this->assertSame(1, Artisan::call('ogamex:bots:log', ['--since' => 'whenever']));
        $this->assertStringContainsString('Could not read', Artisan::output());

        $this->assertSame(1, Artisan::call('ogamex:bots:log', ['--user' => 'NobodyAtAll']));
        $this->assertStringContainsString('No player named', Artisan::output());
    }

    /**
     * Machine formats have to be machine-readable: one JSON object per line, no stray commentary.
     */
    public function testJsonFormatIsOneObjectPerLine(): void
    {
        $this->logDecision('build_building', score: 2.5, reason: 'metal_mine 4, payback 2h', payload: ['building' => 'metal_mine']);
        $this->logDecision('raid', succeeded: false, payload: ['error' => 'nope']);

        ['lines' => $lines, 'stderr' => $stderr] = $this->runLog(['--format' => 'json']);

        // The trailing summary goes to stderr precisely so it cannot corrupt this.
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('2 decision(s)', $stderr);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'Every line must be a JSON object: ' . $line);
            $this->assertSame($this->profile->user_id, $decoded['bot_user_id']);
            $this->assertSame('miner', $decoded['persona']);
        }

        $first = json_decode($lines[0], true);
        $this->assertSame(2.5, $first['score']);
        $this->assertNull($first['error']);

        $second = json_decode($lines[1], true);
        $this->assertSame('nope', $second['error']);
        $this->assertFalse($second['succeeded']);
    }

    /**
     * CSV keeps its header, quotes the fields that need it and leaves the numbers alone so a
     * spreadsheet reads them as numbers.
     */
    public function testCsvFormatIsParseable(): void
    {
        $this->logDecision('build_building', score: 2.5, reason: 'metal_mine 4, payback 2h');

        ['lines' => $lines] = $this->runLog(['--format' => 'csv']);

        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('timestamp,bot_user_id,username,persona,action', $lines[0]);

        $fields = str_getcsv($lines[1], escape: '');
        $this->assertSame((string) $this->profile->user_id, $fields[1]);
        $this->assertSame('miner', $fields[3]);
        $this->assertSame('build_building', $fields[4]);
        $this->assertSame('2.5', $fields[6]);
        $this->assertSame('metal_mine 4, payback 2h', $fields[7]);
    }

    /**
     * Exporting writes a file rather than the screen, and appends rather than destroying whatever
     * was exported before it.
     */
    public function testExportWritesAndAppendsToAFile(): void
    {
        $this->logDecision('build_building', reason: 'first pass');

        $this->assertSame(0, Artisan::call('ogamex:bots:log', ['--out' => $this->exportPath]));
        $this->assertFileExists($this->exportPath);

        $contents = (string) File::get($this->exportPath);
        $this->assertStringContainsString('first pass', $contents);
        $this->assertCount(1, array_filter(explode(PHP_EOL, $contents)));

        // Nothing went to the screen except the confirmation.
        $this->assertStringNotContainsString('first pass', Artisan::output());

        $this->assertSame(0, Artisan::call('ogamex:bots:log', ['--out' => $this->exportPath]));
        $this->assertCount(
            2,
            array_filter(explode(PHP_EOL, (string) File::get($this->exportPath))),
            'A second export must append rather than overwrite the first.'
        );
    }

    /**
     * An appended CSV export keeps one header, not one per run.
     */
    public function testCsvExportWritesOneHeaderPerFile(): void
    {
        $this->logDecision('build_building');

        Artisan::call('ogamex:bots:log', ['--format' => 'csv', '--out' => $this->exportPath]);
        Artisan::call('ogamex:bots:log', ['--format' => 'csv', '--out' => $this->exportPath]);

        $lines = array_values(array_filter(explode(PHP_EOL, (string) File::get($this->exportPath))));

        $this->assertCount(3, $lines, 'One header plus two data rows.');
        $this->assertSame(
            1,
            count(array_filter($lines, fn (string $line) => str_starts_with($line, 'timestamp,'))),
            'A second export must not write another header into the middle of the data.'
        );
    }

    /**
     * The file mirror writes one readable line per decision to the bots channel.
     */
    public function testActivityLogWritesToTheFileChannel(): void
    {
        config(['bots.log.file' => true]);

        Log::shouldReceive('channel')
            ->once()
            ->with('bots')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'build_building')
                    && str_contains($message, 'ok')
                    && str_contains($message, 'metal_mine 4, payback 2h')
                    && $context['bot'] === $this->profile->user_id
                    // Successful lines stay short: the reason already says everything.
                    && !array_key_exists('payload', $context);
            });

        resolve(BotActivityLog::class)->record(
            $this->logDecision('build_building', score: 2.5, reason: 'metal_mine 4, payback 2h'),
            $this->profile->user->username,
            'miner',
        );
    }

    /**
     * A failed decision carries its payload into the file, because working out what the bot
     * thought it was doing is the whole reason to look at a failure.
     */
    public function testActivityLogCarriesThePayloadOnFailure(): void
    {
        config(['bots.log.file' => true]);

        Log::shouldReceive('channel')->once()->with('bots')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) {
            return str_contains($message, 'FAIL')
                && str_contains($message, 'no free fleet slot')
                && ($context['payload']['error'] ?? null) === 'no free fleet slot';
        });

        resolve(BotActivityLog::class)->record(
            $this->logDecision('raid', succeeded: false, payload: ['error' => 'no free fleet slot']),
            $this->profile->user->username,
            'miner',
        );
    }

    /**
     * Turning the file off turns it off. The table is unaffected either way.
     */
    public function testActivityLogRespectsTheConfigSwitch(): void
    {
        config(['bots.log.file' => false]);

        Log::shouldReceive('channel')->never();

        resolve(BotActivityLog::class)->record(
            $this->logDecision('build_building'),
            $this->profile->user->username,
            'miner',
        );
    }
}
