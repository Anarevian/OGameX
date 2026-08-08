<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Bots\Support\ReadsScalarOptions;
use OGame\Enums\BotPersona;
use OGame\Models\User;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Reads the decision log back out.
 *
 * `ogamex:bots:inspect` answers "what is this one bot doing"; this answers "what is the population
 * doing", which is a different question and the one an operator actually asks. It is the same
 * bot_action_log table underneath, presented as a chronological stream that can be filtered,
 * followed live, or written to a file for someone else to read.
 *
 * The three modes it exists to serve:
 *
 *   ogamex:bots:log --follow                       watch the universe play itself
 *   ogamex:bots:log --failed --since=24h           find what the game rejected and why
 *   ogamex:bots:log --limit=0 --format=csv --out=… hand the week to a spreadsheet
 */
#[Description('Show, follow or export the NPC (bot) decision log.')]
#[Signature('ogamex:bots:log
                            {--user= : Only this bot, by user id or username.}
                            {--persona= : Only bots of this persona, e.g. raider.}
                            {--action=* : Only these actions, e.g. --action=raid --action=espionage.}
                            {--failed : Only decisions the game rejected.}
                            {--since= : Only decisions newer than this: 90m, 12h, 7d, or a date.}
                            {--limit=200 : How many decisions to show, most recent first. 0 for all.}
                            {--format=text : Output format: text, json or csv.}
                            {--out= : Append to this file instead of printing to the screen.}
                            {--follow : Keep printing decisions as they are taken. Stop with Ctrl-C.}')]
class ShowBotLog extends Command
{
    use ReadsScalarOptions;

    /**
     * How long to wait between polls in --follow mode.
     *
     * Bots act on the order of minutes, so a tighter loop would only burn CPU asking a question
     * whose answer has not changed.
     */
    private const FOLLOW_POLL_MICROSECONDS = 2_000_000;

    /**
     * Open handle when writing to a file, null when writing to the screen.
     *
     * @var resource|null
     */
    private $handle = null;

    /**
     * Whether the output file had no content before this run, which decides whether it gets a
     * CSV header.
     */
    private bool $fileStartedEmpty = true;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $format = strtolower($this->scalarOption('format') ?? 'text');

        if (!in_array($format, ['text', 'json', 'csv'], true)) {
            $this->error(sprintf('Unknown format "%s". Use text, json or csv.', $format));

            return self::FAILURE;
        }

        try {
            $query = $this->buildQuery();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $out = $this->scalarOption('out');
        if ($out !== null && $out !== '' && !$this->openFile($out)) {
            return self::FAILURE;
        }

        try {
            $rows = $this->fetchTail($query);

            // One header per file. Appending to an export that already has one would produce a
            // second header row in the middle of the data.
            if ($format === 'csv' && ($this->handle === null || $this->fileStartedEmpty)) {
                $this->writeLine('timestamp,bot_user_id,username,persona,action,succeeded,score,reason,tick_id');
            }

            foreach ($rows as $row) {
                $this->writeLine($this->render($row, $format));
            }

            $written = count($rows);
            $lastId = $rows === [] ? $this->highestId($query) : (int) $rows[array_key_last($rows)]->id;

            if ($this->option('follow')) {
                $written += $this->follow($query, $format, $lastId);
            }

            $this->summarise($rows, $written, $out);
        } finally {
            $this->closeFile();
        }

        return self::SUCCESS;
    }

    /**
     * Build the filtered query, oldest decision first.
     *
     * @throws Throwable When a filter names something that does not exist.
     */
    private function buildQuery(): Builder
    {
        // Query builder rather than Eloquent: this is a report over a join, not a set of models,
        // and the join is what makes one row self-describing without a lookup per line.
        $query = DB::table('bot_action_log')
            ->leftJoin('users', 'users.id', '=', 'bot_action_log.bot_user_id')
            ->leftJoin('bot_profiles', 'bot_profiles.user_id', '=', 'bot_action_log.bot_user_id')
            ->select([
                'bot_action_log.id',
                'bot_action_log.bot_user_id',
                'bot_action_log.tick_id',
                'bot_action_log.action',
                'bot_action_log.succeeded',
                'bot_action_log.score',
                'bot_action_log.reason',
                'bot_action_log.payload',
                'bot_action_log.created_at',
                'users.username',
                'bot_profiles.persona',
            ]);

        $user = $this->scalarOption('user');
        if ($user !== null && $user !== '') {
            $query->where('bot_action_log.bot_user_id', $this->resolveUserId($user));
        }

        $persona = $this->scalarOption('persona');
        if ($persona !== null && $persona !== '') {
            $case = BotPersona::tryFrom(strtolower($persona));

            if ($case === null) {
                throw new RuntimeException(sprintf(
                    'Unknown persona "%s". Known personas: %s.',
                    $persona,
                    implode(', ', array_column(BotPersona::cases(), 'value')),
                ));
            }

            $query->where('bot_profiles.persona', $case->value);
        }

        $actions = $this->actionFilter();
        if ($actions !== []) {
            $query->whereIn('bot_action_log.action', $actions);
        }

        if ($this->option('failed')) {
            $query->where('bot_action_log.succeeded', false);
        }

        $since = $this->scalarOption('since');
        if ($since !== null && $since !== '') {
            $cutoff = $this->parseSince($since);

            if ($cutoff === null) {
                throw new RuntimeException(sprintf(
                    'Could not read "%s" as a time. Use a span like 90m, 12h, 7d, or a date.',
                    $since,
                ));
            }

            $query->where('bot_action_log.created_at', '>=', $cutoff);
        }

        return $query->orderBy('bot_action_log.id');
    }

    /**
     * The --action filter, which may be given more than once or comma-separated.
     *
     * @return array<int, string>
     */
    private function actionFilter(): array
    {
        $raw = $this->option('action');
        $values = is_array($raw) ? $raw : [$raw];

        $actions = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            foreach (explode(',', $value) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $actions[] = $part;
                }
            }
        }

        return array_values(array_unique($actions));
    }

    /**
     * Turn a user id or username into a user id.
     *
     * @throws Throwable When no such account exists.
     */
    private function resolveUserId(string $identifier): int
    {
        if (ctype_digit($identifier)) {
            return (int) $identifier;
        }

        $userId = User::where('username', $identifier)->value('id');

        if ($userId === null) {
            throw new RuntimeException(sprintf('No player named "%s".', $identifier));
        }

        return (int) $userId;
    }

    /**
     * Read a relative span like 90m, 12h, 7d, 2w, or fall back to parsing a date.
     */
    private function parseSince(string $value): Carbon|null
    {
        $value = trim($value);

        if (preg_match('/^(\d+)\s*([mhdw])$/i', $value, $matches) === 1) {
            $amount = (int) $matches[1];

            return match (strtolower($matches[2])) {
                'm' => Date::now()->subMinutes($amount),
                'h' => Date::now()->subHours($amount),
                'd' => Date::now()->subDays($amount),
                default => Date::now()->subWeeks($amount),
            };
        }

        try {
            return Date::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Fetch the most recent N decisions, returned oldest first.
     *
     * A log reads forwards, but "the last 200" means the newest 200, so the limit is applied
     * from the far end and the result flipped.
     *
     * @return array<int, stdClass>
     */
    private function fetchTail(Builder $query): array
    {
        $limit = $this->intOption('limit') ?? 200;

        if ($limit <= 0) {
            return $query->get()->all();
        }

        $rows = (clone $query)
            ->reorder('bot_action_log.id', 'desc')
            ->limit($limit)
            ->get()
            ->all();

        return array_reverse($rows);
    }

    /**
     * The newest id matching the filters, used as the starting point when following an empty log.
     */
    private function highestId(Builder $query): int
    {
        return (int) ((clone $query)->reorder('bot_action_log.id', 'desc')->value('bot_action_log.id') ?? 0);
    }

    /**
     * Poll for new decisions until interrupted.
     *
     * @return int How many further decisions were printed.
     */
    private function follow(Builder $query, string $format, int $lastId): int
    {
        if ($this->handle === null) {
            // stderr, for the same reason as the summary: this is for the person watching, and
            // must not land in the middle of a JSON or CSV stream being piped somewhere.
            $this->output->getErrorStyle()->writeln(sprintf(
                '<comment>Following the decision log from id %d. Ctrl-C to stop.</comment>',
                $lastId,
            ));
        }

        $running = true;
        $printed = 0;

        // Catch the interrupt where possible so the file gets flushed and closed and the summary
        // still prints. Without pcntl the loop simply runs until the process is killed, which is
        // what `tail -f` does anyway.
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $stop = function () use (&$running): void {
                $running = false;
            };
            pcntl_signal(SIGINT, $stop);
            pcntl_signal(SIGTERM, $stop);
        }

        while ($running) {
            $rows = (clone $query)->where('bot_action_log.id', '>', $lastId)->get();

            foreach ($rows as $row) {
                $this->writeLine($this->render($row, $format));
                $lastId = (int) $row->id;
                $printed++;
            }

            // Flush as we go: a followed log that only appears on exit is useless.
            if ($this->handle !== null && $rows->isNotEmpty()) {
                fflush($this->handle);
            }

            usleep(self::FOLLOW_POLL_MICROSECONDS);
        }

        return $printed;
    }

    /**
     * Render one decision in the requested format.
     */
    private function render(stdClass $row, string $format): string
    {
        $timestamp = (string) $row->created_at;
        $username = is_string($row->username) ? $row->username : ('#' . $row->bot_user_id);
        $persona = is_string($row->persona) ? $row->persona : '-';
        $succeeded = (bool) $row->succeeded;
        $score = $row->score === null ? null : round((float) $row->score, 2);
        $payload = $this->decodePayload($row);

        // On a failure the exception is the interesting part; the reason describes what it meant
        // to do, which is already implied by the action name.
        $reason = $succeeded
            ? (string) ($row->reason ?? '')
            : (string) ($payload['error'] ?? $row->reason ?? 'failed');

        if ($format === 'json') {
            return (string) json_encode([
                'at' => $timestamp,
                'bot_user_id' => (int) $row->bot_user_id,
                'username' => $username,
                'persona' => $persona,
                'action' => (string) $row->action,
                'succeeded' => $succeeded,
                'score' => $score,
                'reason' => $succeeded ? (string) ($row->reason ?? '') : null,
                'error' => $succeeded ? null : $reason,
                'tick_id' => $row->tick_id,
                'payload' => $payload,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        }

        if ($format === 'csv') {
            return $this->csvRow([
                $timestamp,
                (string) $row->bot_user_id,
                $username,
                $persona,
                (string) $row->action,
                $succeeded ? '1' : '0',
                $score === null ? '' : (string) $score,
                $reason,
                (string) ($row->tick_id ?? ''),
            ]);
        }

        $line = sprintf(
            '%s  %-16s %-10s %-16s %-4s %5s  %s',
            $timestamp,
            $username,
            $persona,
            (string) $row->action,
            $succeeded ? 'ok' : 'FAIL',
            $score === null ? '-' : sprintf('%.2f', $score),
            $reason,
        );

        // Colour is for the terminal only; a file gets plain text so it stays greppable.
        return ($succeeded || $this->handle !== null) ? $line : '<fg=red>' . $line . '</>';
    }

    /**
     * Decode the stored payload, which comes back from the query builder as raw JSON.
     *
     * @return array<string, mixed>
     */
    private function decodePayload(stdClass $row): array
    {
        if (!is_string($row->payload) || $row->payload === '') {
            return [];
        }

        $decoded = json_decode($row->payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Join one CSV row, quoting only the fields that need it.
     *
     * Quoting everything is valid CSV but makes spreadsheets read the numbers as text, which
     * defeats the point of exporting scores.
     *
     * @param array<int, string> $fields
     */
    private function csvRow(array $fields): string
    {
        return implode(',', array_map(
            fn (string $field) => preg_match('/["\n\r,]/', $field) === 1
                ? '"' . str_replace('"', '""', $field) . '"'
                : $field,
            $fields,
        ));
    }

    /**
     * Open the output file for appending, creating its directory if needed.
     */
    private function openFile(string $path): bool
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->error(sprintf('Could not create directory "%s".', $directory));

            return false;
        }

        $this->fileStartedEmpty = !is_file($path) || filesize($path) === 0;

        // Append rather than truncate: exporting twice to the same file should not silently
        // destroy the first export.
        $handle = fopen($path, 'a');

        if ($handle === false) {
            $this->error(sprintf('Could not open "%s" for writing.', $path));

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    /**
     * Close the output file if one is open.
     */
    private function closeFile(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Send one line to wherever the output is going.
     */
    private function writeLine(string $line): void
    {
        if ($this->handle !== null) {
            fwrite($this->handle, $line . PHP_EOL);

            return;
        }

        $this->line($line);
    }

    /**
     * Say what was produced, once the stream is done.
     *
     * Written to stderr rather than stdout so `--format=json | jq` and `--format=csv > file` stay
     * clean: the summary is for the person running the command, not for whatever is reading it.
     *
     * @param array<int, stdClass> $rows
     */
    private function summarise(array $rows, int $written, string|null $out): void
    {
        $stderr = $this->output->getErrorStyle();

        if ($out !== null && $out !== '') {
            $stderr->writeln(sprintf('<info>Wrote %d decision(s) to %s.</info>', $written, $out));

            return;
        }

        if ($this->option('follow')) {
            // The window is open-ended, so the only honest figure is how many lines went past.
            $stderr->writeln(sprintf('<comment>Stopped following after %d decision(s).</comment>', $written));

            return;
        }

        if ($rows === []) {
            $stderr->writeln('<comment>No decisions matched. Bots log a line every time they act; if this is empty they have not acted yet, or the filters are too narrow.</comment>');

            return;
        }

        $failed = count(array_filter($rows, fn (stdClass $row) => !$row->succeeded));

        $stderr->writeln(sprintf(
            '<comment>%d decision(s), %d rejected, %s to %s.</comment>',
            count($rows),
            $failed,
            (string) $rows[0]->created_at,
            (string) $rows[array_key_last($rows)]->created_at,
        ));
    }
}
